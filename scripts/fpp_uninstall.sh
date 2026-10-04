#!/bin/bash
# Runs as root from FPP's Plugin Manager. Safe to run more than once: every
# step is a no-op when there is nothing left to undo.
#
# mpd and mpc are NOT removed here (issue #64). They are declared in
# pluginInfo.json, so the Plugin Manager releases them after this script runs:
# it removes them only if nothing else needs them, and keeps them on a
# Reinstall.

. "${FPPDIR:-/opt/fpp}/scripts/common"

PLUGIN_DATA=${MEDIADIR:-/home/fpp/media}/plugindata
MPD_BACKUP="${PLUGIN_DATA}/fpp-after-hours-mpdOriginal.conf"

echo "Removing cron.d entry"
rm -f /etc/cron.d/fpp-after-hours-cron

# Older versions copied their start/stop scripts into FPP's scripts folder.
# Remove those copies (only if they are this plugin's: they load its class).
for f in fpp-after-hours-start.php fpp-after-hours-stop.php; do
    SCRIPT=${MEDIADIR:-/home/fpp/media}/scripts/$f
    if [ -f "$SCRIPT" ] && grep -q 'fpp-after-hours-class.php' "$SCRIPT"; then
        rm -f "$SCRIPT"
    fi
done

echo "Stopping MPD"
# The plugin's drop-in (removed below) runs mpd as fpp on the plugin's socket;
# don't leave it running that way.
systemctl disable --now mpd.service mpd.socket 2>/dev/null || true

echo "Removing the plugin's PulseAudio socket service"
systemctl disable --now fpp-after-hours-pulse.service 2>/dev/null || true
rm -f /etc/systemd/system/fpp-after-hours-pulse.service
rm -rf /etc/fpp-after-hours
rm -rf /run/fpp-after-hours

echo "Removing MPD systemd drop-in"
rm -f /etc/systemd/system/mpd.service.d/fpp-after-hours.conf
# Older versions named it override.conf - only remove it if it is ours.
OLD_MPD_OVERRIDE=/etc/systemd/system/mpd.service.d/override.conf
if [ -f "$OLD_MPD_OVERRIDE" ] && grep -q '^User=fpp' "$OLD_MPD_OVERRIDE" &&
   grep -q -E 'fpp-after-hours|PIPEWIRE_RUNTIME_DIR=/run/user/' "$OLD_MPD_OVERRIDE"; then
    rm -f "$OLD_MPD_OVERRIDE"
fi
rmdir /etc/systemd/system/mpd.service.d 2>/dev/null || true

# Leftovers from older versions, in case it was never updated before removal.
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
rm -f /etc/dpkg/dpkg.cfg.d/fpp-after-hours
if [ -f /etc/tmpfiles.d/mpd.conf ] && grep -qx 'd /run/mpd 0755 fpp fpp -' /etc/tmpfiles.d/mpd.conf; then
    rm -f /etc/tmpfiles.d/mpd.conf
fi

systemctl daemon-reload

# Only when the legacy hook was still in place: FPP's pulse server is listening
# on the plugin's socket until it restarts without that drop-in.
if [ "$LEGACY_FPP_PULSE_HOOK" = true ]; then
    systemctl try-restart fpp-pipewire-pulse.service || true
fi

echo "Returning MPD's directories to the mpd package"
# /run/mpd: re-apply the package's own tmpfiles rule (owner mpd).
systemd-tmpfiles --create mpd.conf 2>/dev/null || true
if id mpd >/dev/null 2>&1; then
    MPD_GROUP=$(id -gn mpd)
    for d in /var/lib/mpd /var/log/mpd; do
        [ -d "$d" ] && chown -R "mpd:${MPD_GROUP}" "$d"
    done
fi

# Issue #60: put the packaged /etc/mpd.conf back only while mpd is installed
# AND the copy still matches the conffile md5 dpkg recorded, so dpkg always
# recognises the file. Never write it otherwise: an mpd.conf no package owns
# makes the next mpd install stop at a conffile prompt and breaks apt for FPP
# and every other plugin. Either way the copy is no longer needed.
if [ -f "$MPD_BACKUP" ]; then
    MPD_STATUS=$(dpkg-query -W -f='${db:Status-Abbrev}' mpd 2>/dev/null || true)
    MPD_CONF_MD5=$(dpkg-query -W -f='${Conffiles}\n' mpd 2>/dev/null | awk '$1 == "/etc/mpd.conf" { print $2 }')
    if [ "${MPD_STATUS:0:2}" = "ii" ] && [ -n "$MPD_CONF_MD5" ] && [ -f /etc/mpd.conf ] &&
       [ "$(md5sum < "$MPD_BACKUP" | cut -d' ' -f1)" = "$MPD_CONF_MD5" ]; then
        echo "Restoring the packaged /etc/mpd.conf"
        cat "$MPD_BACKUP" > /etc/mpd.conf    # keeps the file's owner and mode
    fi
    rm -f "$MPD_BACKUP"
fi

echo "Removing plugin datafiles (current config file will remain)"
rm -f "${PLUGIN_DATA}/fpp-after-hours-streamRunning"
rm -f "${PLUGIN_DATA}/fpp-after-hours-showVolume"
rm -f "${PLUGIN_DATA}/fpp-after-hours-config.history"

# The plugin's commands stay registered in the running fppd until it restarts,
# so ask the Plugin Manager to show the restart banner.
setSetting restartFlag 1

exit 0
