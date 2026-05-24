# RideSync Web App

Public rider-facing PHP entrypoints remain at the repository web root and `pages/` for Apache compatibility. New web modules should land under this app boundary and route through `backend/controllers`, `backend/services`, and `backend/repositories`.
