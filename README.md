GROUP_ID_DOCKER=`getent group docker | cut -d: -f3`
FILE_GROUP_ID=`stat -c %g /var/run/docker.sock`
  
if [[ $GROUP_ID_DOCKER != $FILE_GROUP_ID ]];
then
   groupdel docker 2>/dev/null
   groupadd --gid $FILE_GROUP_ID docker
fi

usermod -a -G docker www-data

ls -la /var/run/docker.sock