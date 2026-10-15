# WPS Frontend

SPA для Workflow Processing Service: настройка процессов (условия, предзагрузка, изменения, запуск ведомых процессов), ручной запуск, журнал и аудит выполнений, управление пользователями.

Стек: Vue 3 + TypeScript, Vite 5, Element Plus, Pinia, Vue Router, Axios.

## Требования

Node.js 18+ (рекомендуется 22). Если системная версия старше:

```bash
source ~/.nvm/nvm.sh && nvm use 22
```

## Установка

```bash
cd front
npm install
```

## Разработка

```bash
npm run dev
# → http://localhost:3000
# /api проксируется на http://localhost:9501
# другой адрес backend:
VITE_API_TARGET=http://192.168.1.10:9501 npm run dev
```

## Сборка и проверка типов

```bash
npm run typecheck   # vue-tsc --noEmit
npm run build       # проверка типов + сборка в dist/
npm run preview     # локальный просмотр собранной версии
```

## Docker

Образ собирает статику и отдаёт её через nginx (порт 80). Запросы `/api/` проксируются на `http://backend:9501`
(имя сервиса backend в docker-compose), для остальных путей работает SPA fallback на `index.html`.

```bash
docker build -t wps-front ./front
docker run --rm -p 8080:80 --network <сеть-с-backend> wps-front
```

## Роли

| Роль | Возможности в UI |
|---|---|
| admin | всё, включая удаление процессов и управление пользователями |
| editor | создание, редактирование, клонирование и ручной запуск процессов |
| viewer | только просмотр процессов и журнала выполнения |
