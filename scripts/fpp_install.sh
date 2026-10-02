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
# fpp-wireplumber, fpp-pipewire-pulse). MPD must join THAT graph so its stream
# mixes through FPP's sinks alongside normal playback. (Issue #49: pointing MPD
# at a per-user session created a second PipeWire graph whose WirePlumber
# grabbed the ALSA card exclusively, silently blocking fppd.)
#
# MPD runs as fpp, so it cannot use FPP's own pulse socket: every root libpulse
# client FPP runs (e.g. its UI's pactl volume/sink calls, with
# PULSE_RUNTIME_PATH=/run/pipewire-fpp/pulse) "secures" that directory back to
# root:root 0700. Instead, have FPP's pulse server also listen on a socket in a
# directory only this plugin manages. Same server, same graph, same sinks.
PLUGIN_RUN_DIR=/run/fpp-after-hours
PLUGIN_PULSE_SOCKET=${PLUGIN_RUN_DIR}/pulse-native

# Directory for the extra socket, recreated on every boot (and again from the
# pulse unit below, in case tmpfiles ran before this file existed).
mkdir -p /etc/tmpfiles.d
echo "d ${PLUGIN_RUN_DIR} 0755 root root -" > /etc/tmpfiles.d/fpp-after-hours.conf
systemd-tmpfiles --create /etc/tmpfiles.d/fpp-after-hours.conf

# pipewire-pulse drop-in (FPP's unit uses PIPEWIRE_CONFIG_DIR=/etc/pipewire).
# pulse.properties is merged key-by-key, so server.address must keep FPP's
# default "unix:native" alongside ours.
mkdir -p /etc/pipewire/pipewire-pulse.conf.d
cat << EOF > /etc/pipewire/pipewire-pulse.conf.d/90-fpp-after-hours.conf
# Installed by the fpp-after-hours plugin: extra socket for MPD (runs as fpp).
pulse.properties = {
    server.address = [ "unix:native" "unix:${PLUGIN_PULSE_SOCKET}" ]
}
EOF

mkdir -p /etc/systemd/system/mpd.service.d/
cat << EOF > /etc/systemd/system/mpd.service.d/override.conf
[Unit]
# Ordering only - FPP starts its PipeWire stack on demand from fppinit, so we
# must not pull it in early ourselves. The pulse hook below restarts MPD.
After=fpp-pipewire-pulse.service

[Service]
User=fpp
Group=fpp
SupplementaryGroups=audio
Environment="PULSE_SERVER=unix:${PLUGIN_PULSE_SOCKET}"
EOF

# Hook FPP's pulse unit: every time FPP (re)starts its pulse server, restart
# MPD once our socket is listening, since MPD does not reliably reconnect on its
# own. "-" means a hook failure can never fail FPP's own service.
#
# The hook must live outside /home/fpp: Exec* lines run inside FPP's unit,
# whose CapabilityBoundingSet=CAP_SYS_NICE strips root of CAP_DAC_OVERRIDE, so
# it cannot traverse fpp's home directory ("Permission denied"). Install a
# root-owned copy instead (refreshed on every install/upgrade).
PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
PULSE_HOOK=/usr/local/lib/fpp-after-hours/fpp_pulse_hook.sh
install -D -o root -g root -m 0755 "${PLUGIN_DIR}/scripts/fpp_pulse_hook.sh" "${PULSE_HOOK}"
mkdir -p /etc/systemd/system/fpp-pipewire-pulse.service.d/
cat << EOF > /etc/systemd/system/fpp-pipewire-pulse.service.d/fpp-after-hours.conf
[Service]
ExecStartPre=-/bin/mkdir -p ${PLUGIN_RUN_DIR}
ExecStartPost=-${PULSE_HOOK}
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

# Migrate an existing mpd.conf that targets the per-user socket (pre-#49) or
# FPP's own socket (earlier #49 fix). The PHP side (checkForMPDFormat) also
# repairs this, but doing it here means the fix is live as soon as the plugin
# update finishes.
if [ -f /etc/mpd.conf ]; then
    sed -i -E "s#^([[:space:]]*server[[:space:]]+\")(/run/user/[0-9]+/pulse/native|/run/pipewire-fpp/pulse/native)(\")#\\1${PLUGIN_PULSE_SOCKET}\\3#" /etc/mpd.conf
fi

systemctl daemon-reload

# If FPP's pulse server is already running, restart it so it starts listening
# on our socket (this also runs the hook, which restarts MPD). FPP's own UI
# restarts this service on its own, so fppd tolerates it.
systemctl try-restart fpp-pipewire-pulse.service || true

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