# torsade/scripts/ci/debian/Dockerfile
#
# A Debian 13 machine for the test of the installation: systemd runs as its
# first process, as on a server. Beside systemd, only the tools of the test
# itself; the installation brings everything Torsade needs.
FROM debian:trixie

ENV DEBIAN_FRONTEND=noninteractive

# The image of Debian keeps apt from starting the services it installs,
# which a container usually needs; this one runs systemd as a machine does,
# and apt starts them as on a server.
RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        systemd systemd-sysv dbus \
        ca-certificates openssl curl iproute2 nftables procps expect \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /usr/sbin/policy-rc.d

STOPSIGNAL SIGRTMIN+3

CMD ["/sbin/init"]
