#!/bin/bash
# Ensure dpkg auto-resolves conffile prompts with the maintainer's version
# so unattended dependency installs (mpd/mpc) never block on stdin.
sudo mkdir -p /etc/dpkg/dpkg.cfg.d
sudo tee /etc/dpkg/dpkg.cfg.d/fpp-after-hours >/dev/null <<'EOF'
force-confdef
force-confnew
EOF
