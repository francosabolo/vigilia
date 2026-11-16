# DESIGN.md: Seguria

> Criterio de diseño de este proyecto. Lo aplica cualquier persona o agente que diseñe o construya pantallas.
> Cada regla tiene su motivo. Las reglas nuevas se agregan solo con la confirmación del responsable de UX (Franco).

## Marca

### Logo
La lente de cámara con un barrido de vigilancia (`platform/resources/js/components/app-logo-icon.tsx`): un anillo, el barrido y un punto central. El wordmark es `seguria` en minúscula, en Barlow Condensed Semibold.
- El logo va en rojo Seguria sobre grafito o blanco, o en blanco sobre rojo. Nunca en otro color — porque es lo único que identifica la marca en una pantalla llena de cámaras. (origen: aprobado por Franco, 16/11/2026)
- Los íconos de la app (PWA) son la lente roja sobre fondo blanco (`platform/public/icons/`).

### Colores de marca
| Nombre | Hex | Uso |
|---|---|---|
| Rojo Seguria | `#E5252E` | logo, alarmas, botón de pánico |
| Blanco | `#FFFFFF` | texto en la consola, acción principal, fondo de los íconos |
| Grafito | `#1B1D22` | superficies de la consola, texto en la app de vecinos |

- La marca es roja y blanca — porque así lo definió Franco. (origen: 16/11/2026)
- El rojo es la marca y también la alarma: si algo es rojo en pantalla, importa. No se usa rojo para decorar, para links ni para acciones comunes — porque la guardia tiene que poder confiar en que el rojo significa "atender ya". (origen: confirmado por Franco, 16/11/2026)

### Colores funcionales de estado (no son de marca)
| Estado | Consola | App de vecinos |
|---|---|---|
| Alarma / escalado | `#EF3640` | `#E5252E` |
| Pendiente / esperando | `#F5A524` | `#B7791F` |
| En orden / cerrado | `#34C77B` | `#1F8A5B` |

### Forma (referencia Verisure)
- Los botones son píldoras (`rounded-full`). Las tarjetas tienen esquinas grandes (`rounded-2xl`), borde fino y sin sombra — porque transmiten un servicio cercano, no una herramienta técnica. (origen: confirmado por Franco, 16/11/2026)
- Las secciones clave llevan su título con un **ícono de línea dentro de un círculo** (`SectionTitle` / `IconCircle` en `components/icon-circle.tsx`), en grafito. Solo es rojo si la sección es una alarma — porque el rojo se reserva para la marca y las alarmas. (origen: confirmado por Franco, 16/11/2026)
- De Verisure **no** se toma el uso intensivo del rojo, para no romper la regla de señal. (origen: decisión de Franco, 16/11/2026)

### Tipografía
- **Barlow** para la interfaz y **Barlow Condensed** para el wordmark, los títulos, los números grandes y los rótulos de cámara — porque viene de la señalética vial (orientación, lectura rápida a distancia). La condensada entra en los mosaicos de cámaras. (origen: confirmado por Franco, 16/11/2026)
- Sin MAYÚSCULAS en las etiquetas y sin separadores "·" en los textos — porque son recursos genéricos y cuestan más de leer. (origen: confirmado por Franco, 16/11/2026)

## Clientes y vocabulario
- Seguria le vende a **consorcios**, no al cliente final. Hay dos tipos: **edificios** y **countries**. Cada lugar vigilado tiene su tipo (`kind`) y la interfaz usa sus propias palabras — porque un administrador de edificio no habla de "barrios" ni de "lotes". (origen: Franco, 16/11/2026)
- **Edificio:** "el edificio"; el hogar de un vecino es una **unidad** (4B). Lo que se vigila son accesos y puntos sensibles: hall, cochera, puerta de servicio y terraza.
- **Country:** "el country"; el hogar es un **lote** (42). Lo que se vigila es sobre todo el perímetro (alambrado, muro).
- Cuando se habla de los dos a la vez: "Edificios y countries". Nunca "barrio" en la interfaz.
- Para los textos se usan `siteNoun`, `theSite` y `unitNoun` de `lib/seguria.ts`.

## Usuarios y contexto de uso
- **Operador de la central**: monitorea varios edificios y countries en turnos largos, mayormente de noche, en escritorio, con varias pantallas.
- **Guardia del country**: recibe alertas en el celular (WhatsApp o push) y responde caminando. Una mano, poca atención disponible.
- **Administración del consorcio**: configura zonas, horarios y vecinos. Escritorio, de día, uso ocasional. Es quien contrata.
- **Encargado o portero (en edificios)**: recibe las alertas como la guardia y conoce a los vecinos.
- **Vecino**: celular, uso esporádico, a veces en un momento de miedo (pánico). No es técnico.

## Design system
- Tokens: `platform/resources/css/app.css`. La consola usa `:root` en modo claro y `.dark` en modo oscuro (el que va por defecto); los vecinos usan `.theme-vecinos`. El tema se guarda en la cookie `appearance`.
- Componentes: shadcn/ui en `platform/resources/js/components/ui`, más los componentes propios en `components/console`, `incident-badges.tsx` y `site-switcher.tsx`.
- Lienzo aprobado: todavía no hay.

## Tipografía, color y espaciado
- La consola es **clara por defecto**: blanco, grafito y rojo, con mucho aire. El modo oscuro se activa con el botón del header y se recuerda por dispositivo; es el indicado para centrales de monitoreo de noche — porque el cliente es el consorcio, y quien usa la consola es sobre todo la administración y el encargado, de día. (origen: decisión de Franco tomando a Verisure como referencia, 16/11/2026. Reemplaza la regla anterior de oscuro por defecto)
- La app de vecinos es clara — porque se usa de día, desde el celular, y tiene que transmitir calma. (origen: confirmado por Franco, 16/11/2026)

## Layout y navegación
- El edificio o country en pantalla se ve siempre en el header: la central lo cambia desde el selector y la guardia lo ve fijo — porque operar en el lugar equivocado es un error grave. (origen: pedido de Franco, 16/11/2026)
- Las cámaras de un edificio o country tienen que poder verse todas a la vez. (origen: pedido de Franco, 16/11/2026)
- Una sola acción principal por pantalla (botón blanco en la consola, grafito en vecinos). *(principio base de Tónica)*

### Jerarquía de acciones
Hay cuatro niveles y en pantalla nunca conviven dos del mismo nivel alto:

| Nivel | Cómo se ve | Cuándo |
|---|---|---|
| Alarma | Botón rojo relleno ("Atender incidente") | Solo si hay un incidente escalado. Es la única acción que puede estar por encima de la principal. |
| Principal | Píldora rellena: grafito en modo claro y en vecinos, blanco en modo oscuro | Uno por pantalla: la acción del contenido activo ("Invitar vecino", "Guardar ajustes", "Recibido, voy en camino"). |
| Secundaria | Botón gris (`secondary`) o con borde (`outline`) | Acciones alternativas del mismo contenido. |
| Navegación | Link de texto con ícono, sin relleno | Ir a otra pantalla ("Cámaras en vivo", "Incidentes en curso"). |

- La acción principal pertenece al contenido activo (la pestaña o el formulario), no al encabezado. En el encabezado van links de navegación y, si hay una alarma, el botón rojo — porque una acción de navegación en blanco compite con la acción real de la pantalla. (origen: corrección de Franco sobre la pantalla del barrio, 16/11/2026)
- Si hay una alarma activa, la acción principal de la pestaña baja a secundaria: en pantalla queda un solo botón que se destaca, el de atender. (origen: corrección de Franco, 16/11/2026)
- Ir a otra pantalla nunca se diseña como botón relleno: es un link. Un botón ejecuta algo, un link lleva a otro lugar. (origen: corrección de Franco, 16/11/2026)
- Las acciones destructivas dentro de una fila van atenuadas y se ponen rojas solo al pasar el mouse, siempre con confirmación — porque no deberían pesar más que el contenido de la fila. Se resuelven con `ItemAction` de tipo `delete` (ver Acciones sobre elementos). (origen: corrección de Franco, 16/11/2026)

## Acciones sobre elementos (componente `ItemAction`)
Todo elemento que se ve en pantalla (fila, tarjeta, cámara, zona, vecino, edificio o country) muestra sus acciones posibles de forma explícita, con ícono y verbo, a la derecha del elemento. No alcanza con que la fila sea clickeable — porque si no se ve qué se puede hacer, la gente no lo descubre. (origen: pedido de Franco, 16/11/2026)

| Acción | Verbo | Ícono | Cuándo |
|---|---|---|---|
| `view` | Ver (o "Ver en vivo", "Ver edificio") | ojo | Abre el elemento o su detalle |
| `edit` | Editar (o "Editar zonas", "Mover o girar") | lápiz | Cambia el elemento |
| `delete` | Borrar / Quitar | tacho | Siempre con diálogo de confirmación que dice qué se pierde. Gris; rojo solo al pasar el mouse |
| `expand` | Ampliar | flechas | Vista más grande en la misma pantalla |

- Componente: `platform/resources/js/components/item-action.tsx`:
  - `ItemActionLink` navega;
  - `ItemActionButton` actúa en el lugar (por ejemplo, abre un diálogo);
  - `ItemActionHint` es solo visual, para cuando toda la fila ya es un link (por ejemplo, una fila de incidente que muestra "Ver");
  - `ItemActions` agrupa las acciones de un elemento.
- Siempre los mismos verbos y el mismo orden: Ver, Editar, Borrar. Los verbos pueden precisarse ("Ver en vivo", "Editar zonas") pero no cambiarse por sinónimos ("Abrir", "Modificar") — porque la consistencia es lo que hace que se aprendan. (origen: pedido de Franco, 16/11/2026)
- Estas acciones son de nivel secundario o de navegación según la Jerarquía de acciones: nunca son el botón principal relleno de la pantalla.

## Editores de dibujo (zonas sobre la imagen, perímetro sobre el mapa)
- Cada punto dibujado se puede mover arrastrándolo y borrar con una × que aparece sobre el punto (siempre visible en pantallas táctiles). Los puntos son círculos de tamaño fijo, que no se deforman con la imagen — porque si no se entiende cómo corregir un dibujo, la gente lo abandona o lo deja mal. (origen: corrección de Franco sobre el editor de zonas, 16/11/2026)
- Un punto nuevo se inserta en el lado más cercano de la figura, no al final de la lista, para que el polígono nunca se cruce sobre sí mismo. (origen: corrección de Franco, 16/11/2026)
- Las instrucciones del editor se muestran como acciones concretas (Agregar, Mover, Borrar) mientras se dibuja. *(principio base de Tónica)*

## Listados y tablas
- En los incidentes, la severidad se lee en la franja de color a la izquierda de la fila, y el estado en un punto con texto. Nada de pastillas de colores — porque así la fila se lee de un vistazo y el color queda reservado para las señales. (origen: confirmado por Franco, 16/11/2026)
- El orden por defecto es por urgencia: primero los escalados, después los nuevos y al final el resto, del más reciente al más viejo. *(principio base de Tónica)*

## Formularios
- Etiquetas visibles arriba del campo, errores junto al campo y en el lenguaje del usuario. *(principio base de Tónica)*
- Las acciones irreversibles o de alto impacto piden confirmación. Por ejemplo, cerrar un incidente o el pánico (que se confirma con un segundo toque, y se cancela solo a los 5 segundos). *(principio base de Tónica)*

## Estados (vacío, carga, error, éxito, permisos)
- Todo listado tiene un estado vacío que explica qué va a pasar ("Cuando la IA confirme una intrusión, aparece acá"). *(principio base de Tónica)*
- Los vecinos nunca ven cámaras, imágenes ni datos de otras personas. Si intentan entrar a la consola, los lleva a su app — porque es una decisión de privacidad del producto. (origen: decisión de producto, 16/11/2026)

## Textos y tono
- Castellano rioplatense, con voseo y verbos concretos en los botones ("Atender ahora", "Enviar aviso a la guardia", "Recibido, voy en camino"). *(principio base de Tónica)*
- No prometer lo que el sistema no hace (por ejemplo, "la IA aprende de este descarte") — porque en seguridad la confianza es el producto. (origen: confirmado por Franco, 16/11/2026)
- Los plurales se resuelven en el código ("1 incidente", "3 incidentes", con `plural()` de `lib/seguria.ts`). Nunca "incidente(s)" — porque se lee como texto de sistema. (origen: corrección de Franco, 16/11/2026)

## Reglas aprendidas
- El diseño genérico del starter kit se rechazó: hay que partir del contexto del usuario (una sala de control, una guardia en movimiento) y no de un template. (origen: corrección de Franco, 16/11/2026)
- Antes de cerrar una pantalla, contar los botones rellenos visibles a la vez, sumando el encabezado y el contenido. Si hay más de uno (o más de uno además del rojo de alarma), hay que bajar alguno de nivel — porque en la pantalla del barrio "Ver cámaras en vivo" e "Invitar vecino" competían. (origen: corrección de Franco, 16/11/2026)
