#!/bin/sh

# Run the automirror script with a timeout (in seconds)
TIMEOUT=120
MAILREPORT="pgsql-slavestothewww@postgresql.org mha@sollentuna.net dpage@vale-housing.co.uk"

cd /root/pgmirror

/usr/local/bin/php automirror.php "$MAILREPORT" > mirrors.log 2>&1 &
export BG=$!
(sleep $TIMEOUT >/dev/null 2>&1 ; kill ${BG} > /dev/null 2>&1) &
export BG2=$!
wait ${BG} >/dev/null 2>&1
if [ ! $? = 0 ]; then
   echo "An error occured when running the automirror script! Something is wrong!!!" | /usr/sbin/sendmail $MAILREPORT
   exit
fi

kill ${BG2} >/dev/null 2>&1
/usr/local/bind/sbin/rndc reload mirrors.postgresql.org

