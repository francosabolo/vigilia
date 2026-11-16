# Seguria

Monitoreo perimetral con IA para barrios cerrados. La IA mira todas las cámaras y solo escala lo que importa.

```
platform/   Laravel 12 + Inertia (React) + Reverb. Ingest, reglas, IA, alertas, consola. Deploy: Laravel Cloud
edge/       Box en el barrio: Frigate + Mosquitto + seguria-bridge (Python)
```

## Cómo fluye una alerta
1. **Edge**: Frigate detecta una persona o vehículo en una zona. El bridge sube el evento con su foto y su clip a `POST /api/edge/events`.
2. **Reglas** (`DetectionProcessor`): se descarta si la zona no está vigilada, si el objeto no es de un tipo vigilado o si está fuera del horario de armado. En todos esos casos no se llama a la IA.
3. **IA** (`ClaudeDetectionVerifier`): Claude Haiku 5.5 analiza la foto y decide si es una amenaza o no (descarta animales, sombras y guardias de ronda) y la describe en castellano. Si el veredicto es dudoso, lo revisa Sonnet 5.5. Si la IA falla, la alerta sale igual (fail-open).
4. **Incidente**: las detecciones de la misma zona dentro de 2 minutos se agrupan en un solo incidente, con una severidad según el tipo de objeto y la cantidad.
5. **Alerta**: llega a la guardia del barrio por WhatsApp (con botones "Voy" y "Falsa alarma") y por push en la PWA, y aparece en vivo en la consola gracias a Reverb.
6. **Escalamiento**: si nadie responde en `ack_timeout_seconds` (por defecto 180 s), se avisa a los operadores de la central y suena la sirena en la consola.

## Platform (local)
```bash
cd platform
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate && php artisan webpush:vapid && php artisan reverb:install
php artisan migrate --seed          # datos demo (contraseña "password")
composer run dev                    # server + queue + reverb + vite
php artisan seguria:simulate        # dispara una detección de punta a punta
php artisan seguria:fake-edge       # demo sin Frigate: cuadros sintéticos, fotos en vivo y controles de vista
php artisan seguria:fake-edge --move=1:25   # simula que alguien giró 25° la cámara 1
php artisan seguria:fake-edge --cover=2     # simula que taparon la cámara 2
php artisan seguria:simulate-operation --sites=8 --days=7   # 8 barrios simulados, 7 días de operación + reporte
php artisan seguria:simulate-operation --live --speed=30    # operación simulada en vivo (mirá la consola)
php artisan seguria:simulate-operation --reset --sites=0 --days=0   # borra los barrios simulados
php artisan seguria:eval --batch            # mide la IA con el set de storage/app/eval (Batch API: 50% más barato)
php artisan seguria:eval-degrade            # crea 4 niveles más difíciles en storage/app/eval-hard
php artisan seguria:eval-compare            # compara todas las corridas guardadas
php artisan seguria:simulate --image=foto_noche.jpg   # con ANTHROPIC_API_KEY prueba la IA real
php artisan test
```

Usuarios demo: `central@seguria.test` (operador), `admin@seguria.test`, `guardia@seguria.test`, `admin.alamos@seguria.test` (administración del barrio) y `vecino@seguria.test` (vecina del lote 42, que entra a la app de vecinos).

Edge nuevo: `php artisan seguria:edge-create {site_id} edge-norte` imprime el `SEGURIA_TOKEN` una sola vez.

### Roles
| Rol | Ve | Puede |
|---|---|---|
| `admin` / `operator` | todos los barrios de su organización | atender incidentes, configurar zonas, recibir escalamientos |
| `site_admin` | su barrio | atender y configurar zonas, horarios y WhatsApp de la guardia |
| `guard` | su barrio | atender incidentes |
| `resident` | solo la app de vecinos (`/vecinos`), sin cámaras ni imágenes | botón de pánico, "Vi algo raro", modo vacaciones y avisos de su sector |

### Evaluar la IA sin pagar dos veces
- **Set:** `platform/storage/app/eval/{positivos,negativos}`. No se versiona en git porque son imágenes. Las de hoy salieron de Magnific (proyecto "Seguria - Set de evaluación IA"); `seguria:eval-degrade` genera los niveles difíciles.
- **Caché de veredictos** (`storage/app/eval-cache`): se indexa por imagen, modelo y versión del prompt. Repetir una evaluación no se paga; cambiar el prompt invalida solo lo afectado; las imágenes nuevas son lo único que se cobra.
- **`--batch`:** manda lo que no está en caché a la Batch API, que cuesta la mitad y tarda minutos en lugar de segundos.
- **Con la consola de Claude, sin API:**
  1. `seguria:eval-blind <set>` arma una copia anónima y mezclada, con instrucciones en `storage/app/eval-blind/<set>/INSTRUCCIONES.md`; la clave queda aparte, en `storage/app/eval-keys`.
  2. Le pasás esas instrucciones a la consola, o a un subagente.
  3. `seguria:eval-import <set>` puntúa y guarda la corrida. Las imágenes que ya juzgó el mismo juez con el mismo prompt se reutilizan.
- **Corridas** (`storage/app/eval-runs`): todas en el mismo formato, comparables con `seguria:eval-compare`.

### Zonas
Las zonas se dibujan en la consola, sobre una imagen en vivo de cada cámara (**Barrios → cámara → Dibujar zonas**). La consola es la fuente de verdad: el bridge baja las zonas por `/api/edge/commands`, las escribe en `config.yml` (guarda un `.bak`) y reinicia Frigate. Las cámaras que no tienen zonas dibujadas no se tocan.

### Canal de comandos al edge
El edge está detrás de NAT, así que es él quien consulta `GET /api/edge/commands` cada 3 segundos. Ahí recibe:
- pedidos de foto en vivo, que se disparan cuando un vecino avisa algo;
- cuadros para el mosaico de cámaras, solo mientras alguien lo está mirando;
- la versión vigente de las zonas.

### WhatsApp
Hay que crear en Meta la plantilla `alerta_intrusion` (idioma `es_AR`, categoría Utility) con:
- un header de imagen;
- el body `🚨 {{1}} en {{2}}. {{3}}`;
- dos botones de respuesta rápida: "Voy" y "Falsa alarma".

El webhook va en `https://<host>/api/webhooks/whatsapp`. Para la verificación se usa `WHATSAPP_VERIFY_TOKEN`, y la firma se valida con `WHATSAPP_APP_SECRET`.

### Laravel Cloud (checklist de salida)
Infraestructura:
- [ ] Postgres (no SQLite).
- [ ] Bucket de object storage privado y `SEGURIA_MEDIA_DISK=s3`. Las imágenes se sirven con URLs firmadas.
- [ ] Queue worker (`php artisan queue:work`): alertas, escalamiento y revisión de IA.
- [ ] Scheduler activo. Corre `seguria:watch-equipment` cada minuto (edge o cámara caída) y `seguria:prune-media` a diario (retención).
- [ ] Reverb (WebSockets) para la consola en vivo.
- [ ] Servicio de mail (`MAIL_*`) para invitaciones y recuperación de contraseña.
- [ ] Dominio con HTTPS: el push y la PWA lo exigen.

Variables (ver `.env.example`):
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `ANTHROPIC_API_KEY`.
- [ ] `WHATSAPP_*`, con la plantilla aprobada por Meta.
- [ ] `VAPID_*`, generadas con `php artisan webpush:vapid`.
- [ ] `SEGURIA_RETENTION_DAYS`: 30 por defecto.

Antes de abrir:
- [ ] `php artisan migrate --force`. El seeder de demo **no corre en producción** salvo con `SEGURIA_SEED_DEMO=true`. Los simuladores (`seguria:fake-edge`, `seguria:simulate*`) se niegan a correr.
- [ ] Crear el usuario administrador real y dar de alta edificios, countries y edges.
- [ ] Probar una alerta de punta a punta: edge, IA, WhatsApp, push y escalamiento.

## Edge (demo con videos grabados)
```bash
cd edge
cp .env.example .env              # SEGURIA_API_URL + SEGURIA_TOKEN
# dejar perimetro_norte.mp4 y perimetro_sur.mp4 en demo-videos/
docker compose --profile demo up -d
open http://localhost:5000        # UI de Frigate: ajustar las zonas "alambrado"
```

En un barrio real se levanta sin el profile `demo`, con las URLs RTSP del NVR en `frigate-config/config.yml` y el detector `edgetpu` u `openvino`. Las zonas de Frigate se mapean por nombre con las zonas que se cargan en la consola.
