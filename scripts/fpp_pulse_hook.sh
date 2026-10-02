#!/bin/sh
# Runs as root via ExecStartPost of FPP's fpp-pipewire-pulse.service (drop-in
# installed by fpp_install.sh) every time FPP starts or restarts its pulse
# server. Installed to /usr/local/lib/fpp-after-hours/ because FPP's unit runs
# without CAP_DAC_OVERRIDE and cannot read scripts under /home/fpp.
#
# MPD may have started before the pulse server existed, or lost its connection
# when FPP restarted its audio stack, and does not reliably reconnect - so once
# the plugin's socket is listening, restart MPD.

SOCK=/run/fpp-after-hours/pulse-native

# stdout/stderr go to this unit's journal: journalctl -u fpp-pipewire-pulse
log() { echo "fpp-after-hours hook: $*"; }

i=0
while [ $i -lt 50 ] && [ ! -S "$SOCK" ]; do
    sleep 0.2
    i=$((i + 1))
done
if [ -S "$SOCK" ]; then
    log "socket ready: $(ls -l "$SOCK")"
else
    log "socket $SOCK did not appear after 10s - is /etc/pipewire/pipewire-pulse.conf.d/90-fpp-after-hours.conf present?"
fi

# try-restart: only if MPD is running. --no-block: MPD is ordered After= this
# unit, so waiting on its restart job from here would deadlock.
systemctl --no-block try-restart mpd.service || log "mpd try-restart failed"
log "mpd restart queued"

exit 0