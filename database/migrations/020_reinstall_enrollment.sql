-- Allow a signed activation grant to reconnect the same physical laptop
-- after SmartDesa is reinstalled and receives a new local database.
ALTER TABLE village_installations
  ADD COLUMN IF NOT EXISTS enrollment_hardware_hash CHAR(64) NULL AFTER enrollment_device_hash;
