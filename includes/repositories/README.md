# RideSync Repository Layer

Repositories isolate read-heavy database queries from pages, actions, and API endpoints.

Use this layer when a query:

- Powers dashboards or repeated polling/SSE endpoints.
- Joins multiple tables.
- Needs caching, pagination, or index-aware tuning.
- Should not be duplicated across PHP pages.

Current repositories:

- `AdminMetricsRepository.php`: cached admin dashboard/live event metrics.
