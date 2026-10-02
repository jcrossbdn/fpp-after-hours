#!/bin/bash
set -e

# FPP helpers (setSetting, LOGDIR, ...) - the Plugin Manager passes FPPDIR in.
. ${FPPDIR:-/opt/fpp}/scripts/common

# Per-plugin log used by the cron monitor and the command scripts. Create it
# owned by fpp up front: fppd runs the command scripts as root, and a
# root-created log would be unwritable by the fpp-user cron job.
PLUGIN_LOG=${LOGDIR:-/home/fpp/media/logs}/plugin-fpp-after-hours.log
touch "$PLUGIN_LOG"
chown fpp:fpp "$PLUGIN_LOG"
chmod 664 "$PLUGIN_LOG"

# Ensure dpkg auto-resolves conffile prompts with the maintainer's version
# so unattended dependency installs (mpd/mpc) never block on stdin.
mkdir -p /etc/dpkg/dpkg.cfg.d
tee /etc/dpkg/dpkg.cfg.d/fpp-after-hours >/dev/null <<'EOF'
force-confdef
force-confnew
EOF

FPP_UID=$(id -u fpp)

# FPP 10+ runs its own system-level PipeWire instance (fpp-pipewire,
# fpp-wireplumber, fpp-pipewire-pulse) on a private runtime dir. MPD must join
# THAT graph so its stream mixes through FPP's sinks alongside normal playback.
# (Issue #49: pointing MPD at a per-user session created a second PipeWire graph
# whose WirePlumber grabbed the ALSA card exclusively, silently blocking fppd.)
FPP_PW_RUNTIME=/run/pipewire-fpp
FPP_PULSE_SOCKET=${FPP_PW_RUNTIME}/pulse/native

mkdir -p /etc/systemd/system/mpd.service.d/
cat << EOF > /etc/systemd/system/mpd.service.d/override.conf
[Unit]
# Ordering only - FPP starts its PipeWire stack on demand from fppinit, so we
# must not pull it in early ourselves. MPD reconnects if the socket is late.
After=fpp-pipewire-pulse.service

[Service]
User=fpp
Group=fpp
SupplementaryGroups=audio
Environment="PIPEWIRE_RUNTIME_DIR=${FPP_PW_RUNTIME}"
Environment="XDG_RUNTIME_DIR=${FPP_PW_RUNTIME}"
Environment="PULSE_RUNTIME_PATH=${FPP_PW_RUNTIME}/pulse"
Environment="PULSE_SERVER=unix:${FPP_PULSE_SOCKET}"
EOF

# mpd.conf may still specify user/group, which conflicts with the systemd override
[ -f /etc/mpd.conf ] && sed -i -E 's/^[[:space:]]*(user[[:space:]])/#\1/; s/^[[:space:]]*(group[[:space:]])/#\1/' /etc/mpd.conf

# Fix ownership of mpd's data/state directory for the fpp user
[ -d /var/lib/mpd ] && chown -R fpp:fpp /var/lib/mpd

# Override the packaged tmpfiles rule so /run/mpd is owned by fpp on every boot
mkdir -p /etc/tmpfiles.d
echo 'd /run/mpd 0755 fpp fpp -' > /etc/tmpfiles.d/mpd.conf
systemd-tmpfiles --create /etc/tmpfiles.d/mpd.conf

# Fix log directory too, if present
[ -d /var/log/mpd ] && chown -R fpp:fpp /var/log/mpd

# Earlier versions of this plugin unmasked and started a per-user PipeWire /
# WirePlumber / pipewire-pulse stack for the fpp user. FPP deliberately masks
# those units: a second WirePlumber opens the same ALSA cards and, because ALSA
# hw devices are exclusive, FPP's own instance can then no longer play audio.
# Stop that stack if it is running and restore FPP's masking.
USER_PW_UNITS="pipewire.socket pipewire.service pipewire-pulse.socket pipewire-pulse.service wireplumber.service"
STOPPED_USER_PW=false
if [ -d "/run/user/${FPP_UID}" ]; then
    if runuser -u fpp -- env XDG_RUNTIME_DIR=/run/user/${FPP_UID} \
            systemctl --user is-active --quiet pipewire.service wireplumber.service pipewire-pulse.service 2>/dev/null; then
        STOPPED_USER_PW=true
    fi
    runuser -u fpp -- env XDG_RUNTIME_DIR=/run/user/${FPP_UID} \
        systemctl --user disable --now ${USER_PW_UNITS} 2>/dev/null || true
fi
mkdir -p /home/fpp/.config/systemd/user
for svc in ${USER_PW_UNITS}; do
    ln -sf /dev/null /home/fpp/.config/systemd/user/${svc}
done
chown -R fpp:fpp /home/fpp/.config
if [ -d "/run/user/${FPP_UID}" ]; then
    runuser -u fpp -- env XDG_RUNTIME_DIR=/run/user/${FPP_UID} systemctl --user daemon-reload 2>/dev/null || true
fi

# The FPP 10+ branch always runs on FPP's PipeWire backend (the ALSA backend is
# retired), so MPD always uses the pulse output - never direct hw: devices,
# which would contend with FPP for the card in exactly the same way.
echo "pipewire" > /home/fpp/media/plugindata/fpp-after-hours-audioMode
chown fpp:fpp /home/fpp/media/plugindata/fpp-after-hours-audioMode

# Migrate an existing mpd.conf that still targets the per-user socket. The PHP
# side (checkForMPDFormat) also repairs this, but doing it here means the fix is
# live as soon as the plugin update finishes.
if [ -f /etc/mpd.conf ]; then
    sed -i -E "s#^([[:space:]]*server[[:space:]]+\")/run/user/[0-9]+/pulse/native(\")#\\1${FPP_PULSE_SOCKET}\\2#" /etc/mpd.conf
fi

# kill any stale mpd instance holding the port before restarting
pkill -9 mpd 2>/dev/null || true
sleep 1
systemctl daemon-reload
systemctl reset-failed mpd.service 2>/dev/null || true
systemctl restart mpd || true   # readiness loop below reports the failure

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

# commands/descriptions.json is only read when fppd starts, so ask the Plugin
# Manager to show the restart banner rather than restarting fppd ourselves.
setSetting restartFlag 1

# If the old per-user PipeWire stack was holding the sound card, FPP's own
# instance may have already given up on it; a reboot brings it back cleanly.
if [ "$STOPPED_USER_PW" = true ]; then
    echo "NOTE: stopped the per-user PipeWire session left by an older version of this plugin. Reboot to let FPP reclaim the sound card."
    setSetting rebootFlag 1
fi
