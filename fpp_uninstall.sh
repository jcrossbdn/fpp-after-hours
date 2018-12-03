#!/bin/bash
echo "Removing cron.d entry"
rm /etc/cron.d/fpp-after-hours-cron

sleep 5

echo "Removing mpd and mpc"
apt-get -y remove --purge mpd mpc

echo "Removing plugin datafiles (current config file will remain)"
rm /home/fpp/media/plugindata/fpp-after-hours-streamRunning
rm /home/fpp/media/plugindata/fpp-after-hours-showVolume
rm /home/fpp/media/plugindata/fpp-after-hours-mpdOriginal.conf
rm /home/fpp/media/plugindata/fpp-after-hours-config.history
