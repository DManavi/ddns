import boto3

from .utils.ddns import DDNSClient


class AWS(DDNSClient):
    def __init__(self, route53_client):
        self.__route53_client = route53_client

    def __get_hosted_zone_id(self, domain):
        hosted_zones = self.__route53_client.list_hosted_zones_by_name(
            DNSName=domain)
        hosted_zone_id = None
        for zone in hosted_zones['HostedZones']:
            if zone['Name'].rstrip('.') == domain:
                hosted_zone_id = zone['Id'].split('/')[-1]
                break
        if not hosted_zone_id:
            raise Exception(f"Hosted zone for domain '{domain}' not found")

    def __upsert_dns_record(self, domain, subdomain, ip, record_type, ttl):
        hosted_zone_id = self.__get_hosted_zone_id(domain)

        change_batch = {
            'Changes': [
                {
                    'Action': 'UPSERT',
                    'ResourceRecordSet': {
                        'Name': f"{subdomain}.{domain}",
                        'Type': record_type,
                        'TTL': ttl,
                        'ResourceRecords': [{'Value': ip}]
                    }
                }
            ]
        }

        response = self.__route53_client.change_resource_record_sets(
            HostedZoneId=hosted_zone_id,
            ChangeBatch=change_batch
        )
        return response

    def get_dns_record(self, domain, subdomain, record_type):
        hosted_zone_id = self.__get_hosted_zone_id(domain)

        records = self.__route53_client.list_resource_record_sets(
            HostedZoneId=hosted_zone_id)
        for record in records['ResourceRecordSets']:
            if record['Type'] == record_type and record['Name'].rstrip('.') == f"{subdomain}.{domain}":
                return record
        return None

    def create_dns_record(self, domain, subdomain, ip, record_type, ttl):
        return self.__upsert_dns_record(domain, subdomain, ip, record_type, ttl)

    def update_dns_record(self, domain, subdomain, ip, record_type, ttl):
        return self.__upsert_dns_record(domain, subdomain, ip, record_type, ttl)
