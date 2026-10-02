#!/bin/bash
echo "Removing cron.d entry"
rm -rf /etc/cron.d/fpp-after-hours-cron

sleep 1

echo "Removing systemd drop-ins"
rm -f /etc/systemd/system/fpp-pipewire-pulse.service.d/fpp-after-hours.conf
rmdir /etc/systemd/system/fpp-pipewire-pulse.service.d 2>/dev/null || true
rm -rf /usr/local/lib/fpp-after-hours
rm -f /etc/pipewire/pipewire-pulse.conf.d/90-fpp-after-hours.conf
rm -f /etc/tmpfiles.d/fpp-after-hours.conf
rm -rf /run/fpp-after-hours
rm -f /etc/systemd/system/mpd.service.d/override.conf
rmdir /etc/systemd/system/mpd.service.d 2>/dev/null || true
systemctl daemon-reload
systemctl try-restart fpp-pipewire-pulse.service || true

echo "Removing mpd and mpc"
apt-get remove -y --purge mpd mpc

echo "Removing plugin datafiles (current config file will remain)"
rm -rf /home/fpp/media/plugindata/fpp-after-hours-streamRunning
rm -rf /home/fpp/media/plugindata/fpp-after-hours-showVolume
if [ -f /home/fpp/media/plugindata/fpp-after-hours-mpdOriginal.conf ]; then
    cp /home/fpp/media/plugindata/fpp-after-hours-mpdOriginal.conf /etc/mpd.conf
fi
#rm -rf /home/fpp/media/plugindata/fpp-after-hours-mpdOriginal.conf
rm -rf /home/fpp/media/plugindata/fpp-after-hours-config.history

# The plugin's commands stay registered in the running fppd until it restarts,
# so ask the Plugin Manager to show the restart banner.
. ${FPPDIR:-/opt/fpp}/scripts/common
setSetting restartFlag 1