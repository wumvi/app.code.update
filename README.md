#### Что делает
Скачивает с Яндекс.Диск-а архив с кодом по пути:  
```
builds/{project-name}/{ref}.zip
```
и сохраняет на локальной машине по пути  
```
/update/{project-name}/{ref}.zip
```
ограничивает количество файлов в /update/ определённым количеством

Далее запускает на контейнере {container} скрипт  
```
/code.update.sh --project {project-name} --file {local-zip-name}  --ref {ref}
```

#### Запуск
```
php run.php --ref {ref} -s {container} -p {project} --token {token}
```
