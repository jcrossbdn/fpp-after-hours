#!/bin/bash
# Ensure dpkg auto-resolves conffile prompts with the maintainer's version
# so unattended dependency installs (mpd/mpc) never block on stdin.
sudo mkdir -p /etc/dpkg/dpkg.cfg.d
sudo tee /etc/dpkg/dpkg.cfg.d/fpp-after-hours >/dev/null <<'EOF'
force-confdef
force-confnew
EOF

FPP_UID=$(id -u fpp)
mkdir -p /etc/systemd/system/mpd.service.d/
cat << EOF > /etc/systemd/system/mpd.service.d/override.conf
[Service]
User=fpp
Group=fpp
Environment="XDG_RUNTIME_DIR=/run/user/${FPP_UID}"
Environment="PIPEWIRE_RUNTIME_DIR=/run/user/${FPP_UID}"
EOF

# mpd.conf may still specify user/group, which conflicts with the systemd override
sed -i -E 's/^[[:space:]]*(user[[:space:]])/#\1/; s/^[[:space:]]*(group[[:space:]])/#\1/' /etc/mpd.conf

# Fix ownership of mpd's data/state directory for the fpp user
chown -R fpp:fpp /var/lib/mpd

# Override the packaged tmpfiles rule so /run/mpd is owned by fpp on every boot
mkdir -p /etc/tmpfiles.d
echo 'd /run/mpd 0755 fpp fpp -' > /etc/tmpfiles.d/mpd.conf
systemd-tmpfiles --create /etc/tmpfiles.d/mpd.conf

# Fix log directory too, if present
[ -d /var/log/mpd ] && chown -R fpp:fpp /var/log/mpd

loginctl enable-linger fpp
sleep 1   # give logind a moment to create /run/user/${FPP_UID} before we use it below

# If this image has PipeWire user units at all, unmask/enable them for fpp before
# we decide which audio mode to record - order matters here (see notes below).
PIPEWIRE_READY=false
if command -v pipewire >/dev/null 2>&1; then
    sudo -u fpp XDG_RUNTIME_DIR=/run/user/${FPP_UID} systemctl --user unmask \
        pipewire.socket pipewire-pulse.socket pipewire.service pipewire-pulse.service wireplumber.service 2>/dev/null
    sudo -u fpp XDG_RUNTIME_DIR=/run/user/${FPP_UID} systemctl --user enable --now pipewire.socket pipewire-pulse.socket 2>/dev/null
    sudo -u fpp XDG_RUNTIME_DIR=/run/user/${FPP_UID} systemctl --user enable --now pipewire wireplumber pipewire-pulse 2>/dev/null
    sleep 2

    # Confirm the pulse-compatible socket is actually live, not just that units exist
    if sudo -u fpp XDG_RUNTIME_DIR=/run/user/${FPP_UID} pactl info >/dev/null 2>&1; then
        PIPEWIRE_READY=true
    fi
fi

# Record the audio mode AFTER attempting PipeWire startup, based on real socket
# connectivity rather than a pre-startup pgrep check (which would always miss a
# freshly-unmasked-but-not-yet-started PipeWire on first install).
if [ "$PIPEWIRE_READY" = true ]; then
    echo "pipewire" > /home/fpp/media/plugindata/fpp-after-hours-audioMode
else
    echo "alsa" > /home/fpp/media/plugindata/fpp-after-hours-audioMode
fi
chown fpp:fpp /home/fpp/media/plugindata/fpp-after-hours-audioMode

# kill any stale mpd instance holding the port before restarting
pkill -9 mpd 2>/dev/null
sleep 1
systemctl daemon-reload
systemctl reset-failed mpd.service 2>/dev/null
systemctl restart mpd

# Wait for mpd to actually respond, not just report active-in-systemd
MPD_READY=false
for i in $(seq 1 15); do
    if mpc status >/dev/null 2>&1; then
        MPD_READY=true
        break
    fi
    sleep 0.3
done

if [ "$MPD_READY" != true ]; then
    echo "WARNING: mpd failed to start or is not responding. Check 'systemctl status mpd' and 'journalctl -u mpd'." >&2
fi
