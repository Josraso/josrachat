# Changelog de josrachat

## 2.0.11 — 2026-11-12
- Filtro de spam en el formulario de contacto del asistente. Los robots enviaban formularios con letras al azar («eMtQwEmSupjClFcGnyAB»). Ahora se toma por spam, y no se manda el correo, cuando:
  - se rellena un campo trampa invisible para las personas;
  - el formulario llega a los pocos segundos (menos de 4) de abrir el asistente;
  - nadie ha escrito ni pegado nada en el mensaje;
  - el nombre o el mensaje son letras al azar.
  Al robot se le contesta «recibido» como si nada.
- Como mucho 3 formularios por IP en 24 horas (antes era por conversación, y los robots abren una nueva cada vez).
- Nuevo filtro «Spam» en Conversaciones, con lo bloqueado y el motivo, por si alguna vez cae un cliente de verdad. «Todas» ya no lo enseña.
- El formulario de contacto cuenta como mensaje: las conversaciones que solo tienen formulario (sin chat) ya salen en el listado; antes quedaban ocultas. Al actualizar se recuentan y los formularios ya guardados con letras al azar pasan a «Spam».

## 2.0.10 — 2026-11-12
- El cartel de licencia con el botón «Ya tengo la licencia…» sale desde la primera vez.

## 2.0.9 — 2026-11-12
- La licencia la decide el panel del servidor de actualizaciones.

## 2.0.8 — 2026-11-12
- Conexión con el servidor de actualizaciones de josra.
