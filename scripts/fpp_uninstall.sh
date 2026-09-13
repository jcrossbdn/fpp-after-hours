#!/bin/bash
echo "Removing cron.d entry"
rm -rf /etc/cron.d/fpp-after-hours-cron

sleep 1

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
