<?php
// Run only from Laragon Terminal / command line: php create-first-admin.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
if ((int)db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() > 0) { fwrite(STDERR,"Admin accounts already exist. Use the admin panel to manage them.\n"); exit(1); }
$roleStmt=db()->prepare("SELECT id FROM roles WHERE role_key='administrator'");$roleStmt->execute();$roleId=$roleStmt->fetchColumn();if(!$roleId){fwrite(STDERR,"RBAC tables are missing. Import admin/roles-permissions-migration.sql first.\n");exit(1);}
fwrite(STDOUT,'Administrator full name: ');$name=trim((string)fgets(STDIN));
fwrite(STDOUT,'Administrator email: ');$email=trim((string)fgets(STDIN));
fwrite(STDOUT,'Administrator password (12+ characters): ');$password=trim((string)fgets(STDIN));
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12){fwrite(STDERR,"Invalid name/email or password too short. No account created.\n");exit(1);}
db()->prepare("INSERT INTO admin_users(full_name,email,password_hash,role,role_id,is_active) VALUES(?,?,?,'administrator',?,1)")->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$roleId]);
fwrite(STDOUT,"First administrator created. Sign in at /admin/login.php and remove this setup file from the web package.\n");
