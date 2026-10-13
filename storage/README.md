# Private Storage

This directory is reserved for private Cloud Drive storage during local development.

Production Docker deployments use the `cloud_private_storage` named volume mounted at
`/var/cloud-drive/storage`, outside the web server document root.

Do not place this directory under `public/`, and do not commit user-uploaded files.
