#!/bin/sh

apt-get remove hhvm
add-apt-repository -y ppa:mapnik/boost
wget -O- http://dl.hhvm.com/conf/hhvm.gpg.key | apt-key add -
echo deb http://dl.hhvm.com/ubuntu precise main | tee /etc/apt/sources.list.d/hhvm.list
apt-get update -q
apt-get install --assume-no hhvm-nightly
