import requests


def get_ip_addresses():
    ipv4 = requests.get('https://api.ipify.org').text
    ipv6 = requests.get('https://api64.ipify.org').text
    return ipv4, ipv6
