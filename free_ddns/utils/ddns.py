
class DDNSClient:
    def get_dns_record(self, domain, subdomain, record_type):
        raise NotImplementedError()

    def create_dns_record(self, domain, subdomain, ip, record_type, ttl):
        raise NotImplementedError()

    def update_dns_record(self, domain, record_id, ip, record_type, ttl):
        raise NotImplementedError()
