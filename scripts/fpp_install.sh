#!/bin/bash
# Runs as root from FPP's Plugin Manager on install, Reinstall and update.
# Must be safe to run any number of times. mpd/mpc (and pipewire-pulse) are
# installed beforehand by the Plugin Manager from pluginInfo.json.
set -e

# FPP helpers (setSetting, LOGDIR, ...) - the Plugin Manager passes FPPDIR in.
. "${FPPDIR:-/opt/fpp}/scripts/common"

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
PLUGIN_DATA=${MEDIADIR:-/home/fpp/media}/plugindata
MPD_BACKUP="${PLUGIN_DATA}/fpp-after-hours-mpdOriginal.conf"

# Per-plugin log used by the cron monitor and the command scripts. Create it
# owned by fpp up front: fppd runs the command scripts as root, and a
# root-created log would be unwritable by the fpp-user cron job.
PLUGIN_LOG=${LOGDIR}/plugin-fpp-after-hours.log
touch "$PLUGIN_LOG"
chown fpp:fpp "$PLUGIN_LOG"
chmod 664 "$PLUGIN_LOG"

# md5 dpkg recorded for the packaged /etc/mpd.conf (empty if unknown).
mpd_conffile_md5() {
    dpkg-query -W -f='${Conffiles}\n' mpd 2>/dev/null | awk '$1 == "/etc/mpd.conf" { print $2 }'
}

# Stop MPD without touching anyone else's processes or skipping its shutdown
# (issue #63): ask systemd first, then only a still-running process named
# exactly "mpd" gets SIGTERM, and SIGKILL is the last resort.
stop_mpd() {
    systemctl stop mpd.service 2>/dev/null || true
    pgrep -x mpd >/dev/null 2>&1 || return 0
    echo "mpd is still running outside mpd.service - asking it to exit"
    pkill -x mpd 2>/dev/null || true
    for _ in $(seq 1 10); do
        pgrep -x mpd >/dev/null 2>&1 || return 0
        sleep 0.5
    done
    echo "WARNING: mpd did not exit after SIGTERM - sending SIGKILL" >&2
    pkill -9 -x mpd 2>/dev/null || true
}

###############################################################################
# 1. Undo system-wide changes made by earlier versions of this plugin
###############################################################################

# Issue #61: this applied force-confdef/force-confnew to EVERY dpkg run on the
# player. FPP's own apt runs now pass their conffile options per command.
rm -f /etc/dpkg/dpkg.cfg.d/fpp-after-hours

# Issue #62: the extra socket used to be added to FPP's own pulse server with a
# drop-in in /etc/pipewire (read by every pipewire-pulse on the player) and a
# hook on FPP's fpp-pipewire-pulse.service. Both are replaced by this plugin's
# own fpp-after-hours-pulse.service below.
LEGACY_FPP_PULSE_HOOK=false
if [ -e /etc/pipewire/pipewire-pulse.conf.d/90-fpp-after-hours.conf ] ||
   [ -e /etc/systemd/system/fpp-pipewire-pulse.service.d/fpp-after-hours.conf ]; then
    LEGACY_FPP_PULSE_HOOK=true
fi
rm -f /etc/pipewire/pipewire-pulse.conf.d/90-fpp-after-hours.conf
rmdir /etc/pipewire/pipewire-pulse.conf.d 2>/dev/null || true
rm -f /etc/systemd/system/fpp-pipewire-pulse.service.d/fpp-after-hours.conf
rmdir /etc/systemd/system/fpp-pipewire-pulse.service.d 2>/dev/null || true
rm -rf /usr/local/lib/fpp-after-hours
rm -f /etc/tmpfiles.d/fpp-after-hours.conf

# Issue #64: /run/mpd ownership is now handled by the mpd.service drop-in
# (RuntimeDirectory=), not by overriding the package's tmpfiles rule.
if [ -f /etc/tmpfiles.d/mpd.conf ] && grep -qx 'd /run/mpd 0755 fpp fpp -' /etc/tmpfiles.d/mpd.conf; then
    rm -f /etc/tmpfiles.d/mpd.conf
fi

# The mpd drop-in used to be the generic "override.conf" (the name
# `systemctl edit` uses). Remove it only if it is one this plugin wrote.
OLD_MPD_OVERRIDE=/etc/systemd/system/mpd.service.d/override.conf
if [ -f "$OLD_MPD_OVERRIDE" ] && grep -q '^User=fpp' "$OLD_MPD_OVERRIDE" &&
   grep -q -E 'fpp-after-hours|PIPEWIRE_RUNTIME_DIR=/run/user/' "$OLD_MPD_OVERRIDE"; then
    rm -f "$OLD_MPD_OVERRIDE"
fi

###############################################################################
# 2. Keep a pristine copy of the packaged /etc/mpd.conf (issue #60)
###############################################################################
# Uninstall puts this back only if it is still byte-for-byte the conffile dpkg
# recorded, so the plugin never leaves behind an mpd.conf that dpkg doesn't
# recognise (which made the next mpd install stop at a conffile prompt).
MPD_CONF_MD5=$(mpd_conffile_md5)
if [ -f "$MPD_BACKUP" ] &&
   { [ -z "$MPD_CONF_MD5" ] || [ "$(md5sum < "$MPD_BACKUP" | cut -d' ' -f1)" != "$MPD_CONF_MD5" ]; }; then
    # Earlier versions took this copy after editing the file - useless for
    # a restore, and restoring it is what orphaned the file.
    rm -f "$MPD_BACKUP"
fi
if [ ! -f "$MPD_BACKUP" ] && [ -n "$MPD_CONF_MD5" ] && [ -s /etc/mpd.conf ] &&
   [ "$(md5sum < /etc/mpd.conf | cut -d' ' -f1)" = "$MPD_CONF_MD5" ]; then
    cp /etc/mpd.conf "$MPD_BACKUP"
    chown fpp:fpp "$MPD_BACKUP"
fi

###############################################################################
# 3. Let MPD run as fpp
###############################################################################
if [ -s /etc/mpd.conf ]; then
    # user/group in mpd.conf conflict with User=/Group= in the systemd drop-in
    sed -i -E 's/^[[:space:]]*(user[[:space:]])/#\1/; s/^[[:space:]]*(group[[:space:]])/#\1/' /etc/mpd.conf

    # Point an mpd.conf written for the per-user socket (pre-#49) or FPP's own
    # socket (first #49 fix) at this plugin's socket. The PHP side
    # (checkForMPDFormat) also repairs this, but doing it here means the fix is
    # live as soon as the update finishes.
    sed -i -E "s#^([[:space:]]*server[[:space:]]+\")(/run/user/[0-9]+/pulse/native|/run/pipewire-fpp/pulse/native)(\")#\\1/run/fpp-after-hours/pulse-native\\3#" /etc/mpd.conf
fi

# mpd's data/state and log directories
[ -d /var/lib/mpd ] && chown -R fpp:fpp /var/lib/mpd
[ -d /var/log/mpd ] && chown -R fpp:fpp /var/log/mpd

# The FPP 10+ branch always runs on FPP's PipeWire backend (the ALSA backend is
# retired), so MPD always uses the pulse output - never direct hw: devices,
# which would contend with FPP for the card.
echo "pipewire" > "${PLUGIN_DATA}/fpp-after-hours-audioMode"
chown fpp:fpp "${PLUGIN_DATA}/fpp-after-hours-audioMode"

# Earlier versions of this plugin unmasked and started a per-user PipeWire /
# WirePlumber / pipewire-pulse stack for the fpp user. FPP deliberately masks
# those units: a second WirePlumber opens the same ALSA cards and, because ALSA
# hw devices are exclusive, FPP's own instance can then no longer play audio.
# Stop that stack if it is running and restore FPP's masking.
FPP_UID=$(id -u fpp)
USER_PW_UNITS="pipewire.socket pipewire.service pipewire-pulse.socket pipewire-pulse.service wireplumber.service"
STOPPED_USER_PW=false
if [ -d "/run/user/${FPP_UID}" ]; then
    if runuser -u fpp -- env "XDG_RUNTIME_DIR=/run/user/${FPP_UID}" \
            systemctl --user is-active --quiet pipewire.service wireplumber.service pipewire-pulse.service 2>/dev/null; then
        STOPPED_USER_PW=true
    fi
    # shellcheck disable=SC2086  # USER_PW_UNITS is a list: split on purpose
    runuser -u fpp -- env "XDG_RUNTIME_DIR=/run/user/${FPP_UID}" \
        systemctl --user disable --now ${USER_PW_UNITS} 2>/dev/null || true
fi
mkdir -p /home/fpp/.config/systemd/user
for svc in ${USER_PW_UNITS}; do
    ln -sf /dev/null "/home/fpp/.config/systemd/user/${svc}"
done
chown -R fpp:fpp /home/fpp/.config
if [ -d "/run/user/${FPP_UID}" ]; then
    runuser -u fpp -- env "XDG_RUNTIME_DIR=/run/user/${FPP_UID}" systemctl --user daemon-reload 2>/dev/null || true
fi

###############################################################################
# 4. The plugin's own PulseAudio socket on FPP's PipeWire graph (issue #62)
###############################################################################
# See templates/fpp-after-hours-pulse.service for why. Config lives in a
# plugin-owned directory that only that unit reads (PIPEWIRE_CONFIG_DIR): the
# packaged pipewire-pulse.conf (symlinked, so it tracks PipeWire upgrades) plus
# one drop-in. Nothing is added to /etc/pipewire or to FPP's units.
PW_CONF_DIR=/etc/fpp-after-hours/pipewire
if [ ! -f /usr/share/pipewire/pipewire-pulse.conf ] || [ ! -x /usr/bin/pipewire-pulse ]; then
    echo "WARNING: pipewire-pulse is not installed - MPD will have no audio output. Reinstall this plugin from the Plugin Manager." >&2
fi
install -d -m 0755 "${PW_CONF_DIR}/pipewire-pulse.conf.d"
ln -sfn /usr/share/pipewire/pipewire-pulse.conf "${PW_CONF_DIR}/pipewire-pulse.conf"
install -m 0644 "${PLUGIN_DIR}/templates/pipewire/90-fpp-after-hours.conf" "${PW_CONF_DIR}/pipewire-pulse.conf.d/90-fpp-after-hours.conf"
install -m 0644 "${PLUGIN_DIR}/templates/fpp-after-hours-pulse.service" /etc/systemd/system/fpp-after-hours-pulse.service
install -D -m 0644 "${PLUGIN_DIR}/templates/mpd-fpp-after-hours.conf" /etc/systemd/system/mpd.service.d/fpp-after-hours.conf

systemctl daemon-reload

# Stop MPD before its audio path changes underneath it.
stop_mpd

# WantedBy=fpp-pipewire.service: started whenever FPP starts its PipeWire.
systemctl enable fpp-after-hours-pulse.service

# One time only, when migrating from the legacy hook: FPP's pulse server is
# still listening on our socket path until it restarts without the drop-in
# removed above. Later installs never touch FPP's audio services.
if [ "$LEGACY_FPP_PULSE_HOOK" = true ]; then
    echo "Migrating from the shared pulse socket: restarting FPP's pulse server once"
    systemctl try-restart fpp-pipewire-pulse.service || true
fi

# Start (or pick up a changed config for) our socket if FPP's PipeWire is up;
# otherwise it starts along with it.
if systemctl is-active --quiet fpp-pipewire.service; then
    systemctl restart fpp-after-hours-pulse.service ||
        echo "WARNING: fpp-after-hours-pulse failed to start. Check 'journalctl -u fpp-after-hours-pulse'." >&2
fi

###############################################################################
# 5. Stream monitor
###############################################################################
# Installed here rather than from the plugin page, so it exists as soon as the
# plugin is installed and is plainly one of the install's system changes.
sed "s#@PLUGIN_DIR@#${PLUGIN_DIR}#" "${PLUGIN_DIR}/templates/fpp-after-hours-cronTemplate" > /etc/cron.d/fpp-after-hours-cron
chmod 0644 /etc/cron.d/fpp-after-hours-cron

###############################################################################
# 6. Start MPD
###############################################################################
systemctl reset-failed mpd.service 2>/dev/null || true
# Uninstall disables mpd (so a Reinstall needs this); harmless otherwise.
systemctl enable mpd.service 2>/dev/null || true
systemctl start mpd.service || true   # readiness check below reports a failure

# Write the plugin's audio_output block now rather than on the first page load
# or start command. Run as fpp so plugindata files stay fpp-owned.
runuser -u fpp -- /usr/bin/php -r \
    'require $argv[1]; new fppAfterHours(true);' "${PLUGIN_DIR}/fpp-after-hours-class.php" \
    >> "$PLUGIN_LOG" 2>&1 || true

# Wait for mpd to actually respond, not just report active-in-systemd
MPD_READY=false
for _ in $(seq 1 15); do
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
