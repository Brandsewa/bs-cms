FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y openssh-server rsync bash curl unzip && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/run/sshd /home/u497383174/domains/bskickstarter.com/public_html /home/u497383174/domains/bskickstarter.com/core

RUN useradd -m -d /home/u497383174 -s /bin/bash -u 1001 u497383174 && \
    echo 'u497383174:hostinger' | chpasswd

RUN mkdir -p /home/u497383174/.ssh && \
    chmod 700 /home/u497383174/.ssh && \
    chown -R u497383174:u497383174 /home/u497383174

RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin no/' /etc/ssh/sshd_config

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D", "-e"]
