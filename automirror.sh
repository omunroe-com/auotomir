#!/bin/sh

# Run the automirror script with a timeout (in seconds)
TIMEOUT=120
MAILREPORT="sysadmin-reports@postgresql.org"

cd /usr/local/automirror/automirror

/usr/bin/php automirror.php "$MAILREPORT" > mirrors.log 2>&1 &
export BG=$!
(sleep $TIMEOUT >/dev/null 2>&1 ; kill ${BG} > /dev/null 2>&1) &
export BG2=$!
wait ${BG} >/dev/null 2>&1
if [ ! $? = 0 ]; then
   echo "An error occured when running the automirror script! Something is wrong!!!" | /bin/mail -s "automirror error report" $MAILREPORT
   exit
fi

kill ${BG2} >/dev/null 2>&1
sudo /usr/sbin/rndc reload mirrors.postgresql.org
if [ ! $? = 0 ]; then
	echo "failed to reload mirrors.postgresql.org zone" | /bin/mail -s "automirror failure report" $MAILREPORT
	exit
fi
