import requests

from .utils.ddns import DDNSClient


class DigitalOcean(DDNSClient):
    def __init__(self, api_key):
        self.__api_key = api_key

    def get_dns_record(self, domain, subdomain, record_type):
        url = f"https://api.digitalocean.com/v2/domains/{domain}/records"
        headers = {
            "Authorization": f"Bearer {self.__api_key}",
            "Content-Type": "application/json"
        }
        response = requests.get(url, headers=headers)
        response.raise_for_status()
        records = response.json()['domain_records']
        for record in records:
            if record['type'] == record_type and record['name'] == subdomain:
                return record
        return None

    def create_dns_record(self, domain, subdomain, ip, record_type, ttl):
        url = f"https://api.digitalocean.com/v2/domains/{domain}/records"
        headers = {
            "Authorization": f"Bearer {self.__api_key}",
            "Content-Type": "application/json"
        }
        data = {
            "type": record_type,
            "name": subdomain,
            "data": ip,
            "ttl": ttl
        }
        response = requests.post(url, headers=headers, json=data)
        response.raise_for_status()
        return response.json()

    def update_dns_record(self, domain, record_id, ip, record_type, ttl):
        url = f"https://api.digitalocean.com/v2/domains/{domain}/records/{record_id}"
        headers = {
            "Authorization": f"Bearer {self.__api_key}",
            "Content-Type": "application/json"
        }
        data = {
            "data": ip,
            "type": record_type,
            "ttl": ttl
        }
        response = requests.put(url, headers=headers, json=data)
        response.raise_for_status()
        return response.json()
