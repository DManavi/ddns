import requests
import argparse
import time

from ..utils import get_ip_addresses





def main():
    parser = argparse.ArgumentParser(
        description='Update DigitalOcean DNS records with current IP addresses.')
    parser.add_argument('--domain', type=str, required=True,
                        help='The domain name to update.')
    parser.add_argument('--subdomain', type=str, required=True,
                        help='The subdomain name to update.')
    parser.add_argument('--api-key', type=str, required=True,
                        help='The DigitalOcean API key.')
    parser.add_argument('--ttl', type=int, required=True,
                        help='The TTL for the DNS records.')
    args = parser.parse_args()

    while True:
        ipv4, ipv6 = get_ip_addresses()

        ip_addresses_records = [
            ('A', ipv4),
        ]

        if ':' in ipv6:
            ip_addresses_records.append(('AAAA', ipv6))

        for record_type, ip in ip_addresses_records:
            record = get_dns_record(
                args.domain, args.subdomain, args.api_key, record_type)
            if record:
                if record['data'] != ip:
                    update_dns_record(args.domain, args.api_key,
                                      record['id'], ip, record_type, args.ttl)
                    print(f"Updated {record_type} record to {ip}")
                else:
                    print(f"No update needed for {record_type} record")
            else:
                create_dns_record(args.domain, args.subdomain,
                                  args.api_key, ip, record_type, args.ttl)
                print(f"Created {record_type} record with {ip}")

        time.sleep(30)


if __name__ == "__main__":
    main()
