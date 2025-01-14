import argparse
import yaml


def create_config_file(domain, subdomain, api_key, ttl):
    file_path = f"ddns-{subdomain}.{domain}-do.yaml"
    config = {
        "domain": domain,
        "subdomain": subdomain,
        "api_key": api_key,
        "ttl": ttl,
    }

    with open(file_path, 'w') as f:
        yaml.dump(data=config, steam=f)
    return file_path


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
    args = parser.parse_args()

    create_config_file(args.domain, args.subdomain,
                       args.api_key, args.ttl, args.username)


if __name__ == "__main__":
    main()
