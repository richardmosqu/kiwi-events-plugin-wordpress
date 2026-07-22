# KE Sold Audit — herramienta opcional de verificación

Complemento **opcional y aparte** de Kiwi Events. No forma parte del core y no
cambia nada del plugin principal: es una utilidad de **solo lectura** que puedes
instalar cuando necesites revisar **a fondo** por qué el conteo de "vendidos" del
panel de organizador no coincide con la lista de asistentes del admin.

> ⚠️ **Solo diagnóstico.** Ejecuta únicamente consultas `SELECT`. No escribe, no
> actualiza, no borra y no "repara" contadores. Puedes instalarla, usarla y
> desinstalarla sin riesgo.

## Para qué sirve

Para un evento, reconcilia las dos cifras que se contradicen:

| Superficie | Cómo cuenta |
|---|---|
| Panel de organizador — **"Sold"** | `SUM(ke_ticket_types.quantity_sold)`, solo tipos no archivados |
| Lista de asistentes del admin | `COUNT` de filas reales en `ke_tickets` |

y atribuye **cada ticket faltante** a una causa concreta:

- **cancelados / reembolsados** — excluidos legítimamente de "vendidos"
- **tipos de boleto archivados con ventas** — ventas reales que el panel descarta
- **`ticket_type_id` huérfano / de otro evento** — filas que ya no resuelven
- **desfase de contador** (`quantity_sold ≠ filas reales`) — sobre/sub-conteo

Muestra además: filas por estado, tabla por tipo de boleto, desglose de órdenes
por `payment_status`, la cifra de ingreso neto del panel, y un **veredicto** que
dice si el número correcto es 24 o 37 (o el que aplique) para ese evento.

## Instalar

1. Descarga el ZIP desde la sección **Releases** del repositorio
   (`ke-sold-audit-vX.Y.Z` → `ke-sold-audit.zip`).
2. En wp-admin: **Plugins → Añadir nuevo → Subir plugin** → elige el ZIP →
   **Instalar** → **Activar**.
3. Abre **"KE Sold Audit"** en el menú lateral (ícono de gráfico), elige el
   evento y pulsa **Run Diagnostic**.

Requiere el plugin **Kiwi Events** activo (lee sus tablas `ke_tickets`,
`ke_ticket_types`, `ke_orders`). Acceso limitado a `manage_options`.

## Notas

- Plugin estándar (no mu-plugin) → funciona en WordPress.com Business.
- Toma los design tokens y el modo oscuro del admin de Kiwi Events para verse
  integrado.
- Segura para dejar instalada o borrar cuando termines. No cambia datos.

## Empaquetar el ZIP (para mantenedores)

```bash
cd plugins
zip -r ke-sold-audit.zip ke-sold-audit -x "*.DS_Store"
```
