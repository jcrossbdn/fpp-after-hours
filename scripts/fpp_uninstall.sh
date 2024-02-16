#!/bin/bash
echo "Removing cron.d entry"
rm -rf /etc/cron.d/fpp-after-hours-cron

sleep 5

echo "Removing mpd and mpc"
apt -y remove --purge mpd mpc

echo "Removing plugin datafiles (current config file will remain)"
rm -rf /home/fpp/media/plugindata/fpp-after-hours-streamRunning
rm -rf /home/fpp/media/plugindata/fpp-after-hours-showVolume
sudo cp /home/fpp/media/plugindata/fpp-after-hours-mpdOriginal.conf /etc/mpd.conf
#rm -rf /home/fpp/media/plugindata/fpp-after-hours-mpdOriginal.conf
rm -rf /home/fpp/media/plugindata/fpp-after-hours-config.history
