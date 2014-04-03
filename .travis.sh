#!/bin/sh

wget -O- http://dl.hhvm.com/conf/hhvm.gpg.key | apt-key add -
add-apt-repository "deb http://dl.hhvm.com/ubuntu precise main"
apt-get remove hhvm
add-apt-repository -y ppa:mapnik/boost
apt-get update -q
apt-get install hhvm-nightly
