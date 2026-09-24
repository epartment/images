# Debian trixie: bullseye's security pool was purged when its LTS ended (2026-08-31), which broke
# every apt install here. The golang image already ships git, so no apt step is needed to build.
FROM golang:trixie AS builder
RUN mkdir -p /root/gocode \
  && export GOPATH=/root/gocode \
  && go install github.com/jpillora/webproc@v0.4.0

FROM debian:trixie-slim
LABEL maintainer="support@epartment.nl"
# webproc release settings
COPY --from=builder /root/gocode/bin/webproc /usr/local/bin
# fetch dnsmasq and webproc binary
RUN apt-get update \
	&& apt-get install -y dnsmasq curl \
	&& chmod +x /usr/local/bin/webproc \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
#configure dnsmasq
RUN mkdir -p /etc/default/
RUN echo -e "ENABLED=1\nIGNORE_RESOLVCONF=yes" > /etc/default/dnsmasq
COPY dnsmasq.conf /etc/dnsmasq.conf
#run!
ENTRYPOINT ["webproc","--configuration-file","/etc/dnsmasq.conf","--","dnsmasq","--no-daemon"]