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

### Tipografía
- **Barlow** para la interfaz y **Barlow Condensed** para el wordmark, los títulos, los números grandes y los rótulos de cámara — porque viene de la señalética vial (orientación, lectura rápida a distancia). La condensada entra en los mosaicos de cámaras. (origen: confirmado por Franco, 16/11/2026)
- Sin MAYÚSCULAS en las etiquetas y sin separadores "·" en los textos — porque son recursos genéricos y cuestan más de leer. (origen: confirmado por Franco, 16/11/2026)

## Usuarios y contexto de uso
- **Operador de la central**: monitorea varios barrios en turnos largos, mayormente de noche, en escritorio, con varias pantallas.
- **Guardia del barrio**: recibe alertas en el celular (WhatsApp o push) y responde caminando. Una mano, poca atención disponible.
- **Administración del barrio**: configura zonas, horarios y vecinos. Escritorio, uso ocasional.
- **Vecino**: celular, uso esporádico, a veces en un momento de miedo (pánico). No es técnico.

## Design system
- Tokens: `platform/resources/css/app.css` (tema de la consola en `:root`, tema de vecinos en `.theme-vecinos`).
- Componentes: shadcn/ui en `platform/resources/js/components/ui`, más los componentes propios en `components/console`, `incident-badges.tsx` y `site-switcher.tsx`.
- Lienzo aprobado: todavía no hay.

## Tipografía, color y espaciado
- La consola es siempre oscura, en grafito, sin modo claro — porque se usa de noche y las imágenes de las cámaras tienen que resaltar. (origen: confirmado por Franco, 16/11/2026)
- La app de vecinos es clara — porque se usa de día, desde el celular, y tiene que transmitir calma. (origen: confirmado por Franco, 16/11/2026)

## Layout y navegación
- El barrio en pantalla se ve siempre en el header: la central lo cambia desde el selector y la guardia lo ve fijo — porque operar en el barrio equivocado es un error grave. (origen: pedido de Franco, 16/11/2026)
- Las cámaras de un barrio tienen que poder verse todas a la vez. (origen: pedido de Franco, 16/11/2026)
- Una sola acción principal por pantalla (botón blanco en la consola, grafito en vecinos). *(principio base de Tónica)*

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

## Reglas aprendidas
- El diseño genérico del starter kit se rechazó: hay que partir del contexto del usuario (una sala de control, una guardia en movimiento) y no de un template. (origen: corrección de Franco, 16/11/2026)
