import os
import argparse


def create_service_file(domain, subdomain, api_key, ttl, username):
    script_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.')
    service_name = f"ddns-{subdomain}.{domain}-do"
    service_content = f"""
[Unit]
Description=Update DigitalOcean DNS records with current IP addresses
After=network.target

[Service]
ExecStart=/usr/bin/python3 -u {script_path} --domain {domain} --subdomain {subdomain} --api-key {api_key} --ttl {ttl}
Restart=always
RestartSec=15
User={username}

[Install]
WantedBy=multi-user.target
"""
    service_file_path = f'/etc/systemd/system/{service_name}.service'
    with open(service_file_path, 'w') as f:
        f.write(service_content)
    return service_name


def main():
    parser = argparse.ArgumentParser(
        description='Create a systemd service to update DigitalOcean DNS records.')
    parser.add_argument('--domain', type=str, required=True,
                        help='The domain name to update.')
    parser.add_argument('--subdomain', type=str, required=True,
                        help='The subdomain name to update.')
    parser.add_argument('--api-key', type=str, required=True,
                        help='The DigitalOcean API key.')
    parser.add_argument('--ttl', type=int, required=True,
                        help='The TTL for the DNS records.')
    parser.add_argument('--username', type=str, required=True,
                        help='The username to run the service as.')
    args = parser.parse_args()

    service_name = create_service_file(
        args.domain, args.subdomain, args.api_key, args.ttl, args.username)

    # Reload systemd manager configuration
    os.system('systemctl daemon-reload')

    # Enable the service to start on boot
    os.system(f'systemctl enable {service_name}.service')

    # Start the service immediately
    os.system(f'systemctl start {service_name}.service')


if __name__ == "__main__":
    main()
