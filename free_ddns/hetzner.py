import requests

from .utils.ddns import DDNSClient


class Hetzner(DDNSClient):
    def __init__(self, api_key):
        self.__api_key = api_key

    def __get_dns_zone_id(self, domain):
        url = "https://dns.hetzner.com/api/v1/zones"
        headers = {
            "Auth-API-Token": self.__api_key,
            "Content-Type": "application/json"
        }
        params = {
            "search_name": domain
        }
        response = requests.get(url, headers=headers, params=params)
        response.raise_for_status()
        zones = response.json().get("zones", [])
        for zone in zones:
            if zone["name"] == domain:
                return zone["id"]
        return None

    def get_dns_record(self, domain, subdomain, record_type):
        zone_id = self.__get_dns_zone_id(domain)

        url = "https://dns.hetzner.com/api/v1/records"
        headers = {
            "Auth-API-Token": self.__api_key,
            "Content-Type": "application/json"
        }
        params = {
            "zone_id": zone_id,
            "name": subdomain,
            "type": record_type
        }
        response = requests.get(url, headers=headers, params=params)
        response.raise_for_status()
        records = response.json().get("records", [])
        for record in records:
            if record["name"] == subdomain and record["type"] == record_type:
                return record
        return None

    def create_dns_record(self, domain, subdomain, ip, record_type, ttl):
        url = "https://dns.hetzner.com/api/v1/records"
        headers = {
            "Auth-API-Token": self.__api_key,
            "Content-Type": "application/json"
        }
        data = {
            "zone_id": domain,
            "type": record_type,
            "name": subdomain,
            "value": ip,
            "ttl": ttl
        }
        response = requests.post(url, headers=headers, json=data)
        response.raise_for_status()
        return response.json()

    def update_dns_record(self, domain, record_id, ip, record_type, ttl):
        url = f"https://dns.hetzner.com/api/v1/records/{record_id}"
        headers = {
            "Auth-API-Token": self.__api_key,
            "Content-Type": "application/json"
        }
        data = {
            "zone_id": domain,
            "type": record_type,
            "name": subdomain,
            "value": ip,
            "ttl": ttl
        }
        response = requests.put(url, headers=headers, json=data)
        response.raise_for_status()
        return response.json()
