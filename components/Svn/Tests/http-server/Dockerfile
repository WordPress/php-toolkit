# Apache + mod_dav_svn server used by the Svn component's HTTP
# integration tests. See WithSvnHttpServerTrait.php for how it is built
# and started.
FROM debian:bookworm-slim

RUN apt-get update \
	&& apt-get install -y --no-install-recommends apache2 libapache2-mod-svn subversion \
	&& rm -rf /var/lib/apt/lists/*

RUN a2enmod dav dav_svn authn_core authn_file auth_basic authz_user

COPY httpd-svn.conf /etc/apache2/conf-enabled/svn.conf
COPY entrypoint.sh /entrypoint.sh
COPY create-repo.sh /create-repo.sh
RUN chmod +x /entrypoint.sh /create-repo.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
