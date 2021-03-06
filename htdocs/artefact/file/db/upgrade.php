<?php
/**
 *
 * @package    mahara
 * @subpackage artefact-file
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

defined('INTERNAL') || die();

function xmldb_artefact_file_upgrade($oldversion=0) {

    $status = true;

    if ($oldversion < 2007010900) {
        $table = new XMLDBTable('artefact_file_files');
        $field = new XMLDBField('adminfiles');
        $field->setAttributes(XMLDB_TYPE_INTEGER, 1, false, true, false, null, null, 0);
        add_field($table, $field);
        set_field('artefact_file_files', 'adminfiles', 0);

        // Put all folders into artefact_file_files
        $folders = get_column_sql("
            SELECT a.id
            FROM {artefact} a
            LEFT OUTER JOIN {artefact_file_files} f ON a.id = f.artefact
            WHERE a.artefacttype = 'folder' AND f.artefact IS NULL");
        if ($folders) {
            foreach ($folders as $folderid) {
                $data = (object) array('artefact' => $folderid, 'adminfiles' => 0);
                insert_record('artefact_file_files', $data);
            }
        }
    }

    if ($oldversion < 2007011800) {
        // Make sure the default quota is set
        set_config_plugin('artefact', 'file', 'defaultquota', 10485760);
    }

    if ($oldversion < 2007011801) {
        // Create image table
        $table = new XMLDBTable('artefact_file_image');

        $table->addFieldInfo('artefact', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
        $table->addFieldInfo('width', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
        $table->addFieldInfo('height', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null);

        $table->addKeyInfo('artefactfk', XMLDB_KEY_FOREIGN, array('artefact'), 'artefact', array('id'));

        $status = $status && create_table($table);

        $images = get_column('artefact', 'id', 'artefacttype', 'image');
        log_debug(count($images));
        require_once(get_config('docroot') . 'artefact/lib.php');
        foreach ($images as $imageid) {
            $image = artefact_instance_from_id($imageid);
            $path = $image->get_path();
            $image->set('dirty', false);
            $data = new StdClass;
            $data->artefact = $imageid;
            if (file_exists($path)) {
                list($data->width, $data->height) = getimagesize($path);
            }

            if (empty($data->width) || empty($data->height)) {
                $data->width = 0;
                $data->height = 0;
            }
            insert_record('artefact_file_image', $data);
        }
    }

    if ($oldversion < 2007013100) {
        // Add new tables for file/mime types
        $table = new XMLDBTable('artefact_file_file_types');

        $table->addFieldInfo('description', XMLDB_TYPE_TEXT, 128, null, XMLDB_NOTNULL);
        $table->addFieldInfo('enabled', XMLDB_TYPE_INTEGER, 1, null, XMLDB_NOTNULL, null, null, null, 1);

        $table->addKeyInfo('primary', XMLDB_KEY_PRIMARY, array('description'));

        create_table($table);

        $table = new XMLDBTable('artefact_file_mime_types');

        $table->addFieldInfo('mimetype', XMLDB_TYPE_TEXT, 128, null, XMLDB_NOTNULL);
        $table->addFieldInfo('description', XMLDB_TYPE_TEXT, 128, null, XMLDB_NOTNULL);

        $table->addKeyInfo('primary', XMLDB_KEY_PRIMARY, array('mimetype'));
        $table->addKeyInfo('descriptionfk', XMLDB_KEY_FOREIGN, array('description'), 'artefact_file_file_types', array('description'));

        create_table($table);

        safe_require('artefact', 'file');
        PluginArtefactFile::resync_filetype_list();
    }

    if ($oldversion < 2007021400) {
        $table = new XMLDBTable('artefact_file_files');
        $field = new XMLDBField('oldextension');
        $field->setAttributes(XMLDB_TYPE_TEXT);
        add_field($table, $field);
    }

    if ($oldversion < 2007042500) {
        // migrate everything we had to change to  make mysql happy
        execute_sql("ALTER TABLE {artefact_file_file_types} ALTER COLUMN description TYPE varchar(32)");
        execute_sql("ALTER TABLE {artefact_file_mime_types} ALTER COLUMN mimetype TYPE varchar(128)");
        execute_sql("ALTER TABLE {artefact_file_mime_types} ALTER COLUMN description TYPE varchar(32)");

    }

    if ($oldversion < 2008091100) {
        $table = new XMLDBTable('artefact_file_files');
        $field = new XMLDBField('fileid');
        $field->setAttributes(XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null);
        add_field($table, $field);
        execute_sql("UPDATE {artefact_file_files} SET fileid = artefact WHERE NOT size IS NULL");
    }

    if ($oldversion < 2008101602) {
        $table = new XMLDBTable('artefact_file_files');
        $field = new XMLDBField('filetype');
        $field->setAttributes(XMLDB_TYPE_TEXT);
        add_field($table, $field);
        // Guess mime type for existing files
        $fileartefacts = get_records_sql_array('
            SELECT
                a.artefacttype, f.artefact, f.oldextension, f.fileid
            FROM
                {artefact} a,
                {artefact_file_files} f
            WHERE
                a.id = f.artefact
        ', array());
        require_once(get_config('libroot') . 'file.php');
        if ($fileartefacts) {
            foreach ($fileartefacts as $a) {
                $type = null;
                if ($a->artefacttype == 'image') {
                    $size = getimagesize(get_config('dataroot') . 'artefact/file/originals/' . ($a->fileid % 256) . '/' . $a->fileid);
                    $type = $size['mime'];
                }
                else if ($a->artefacttype == 'profileicon') {
                    $size = getimagesize(get_config('dataroot') . 'artefact/file/profileicons/originals/' . ($a->fileid % 256) . '/' . $a->fileid);
                    $type = $size['mime'];
                }
                else if ($a->artefacttype == 'file') {
                    $file = get_config('dataroot') . 'artefact/file/originals/' . ($a->fileid % 256) . '/' . $a->fileid;
                    switch (strtolower(PHP_OS)) {
                        case 'win' :
                            throw new SystemException('retrieving filetype not supported in windows');
                        default :
                            $filepath = get_config('pathtofile');
                            if (!empty($filepath)) {
                                list($output,) = preg_split('/[\s;]/', exec($filepath . ' -ib ' . escapeshellarg($file)));
                                $type = $output;
                            }
                            $type = false;
                    }
                }
                if ($type) {
                    set_field('artefact_file_files', 'filetype', $type, 'artefact', $a->artefact);
                }
            }
        }
        delete_records('config', 'field', 'pathtofile');
    }

    if ($oldversion < 2008101701) {
        if ($data = get_config_plugin('blocktype', 'internalmedia', 'enabledtypes')) {
            $olddata = unserialize($data);
            $newdata = array();
            foreach ($olddata as $d) {
                if ($d == 'mov') {
                    $newdata[] = 'quicktime';
                }
                else if ($d == 'mp4') {
                    $newdata[] = 'mp4_video';
                }
                else if ($d != 'mpg') {
                    $newdata[] = $d;
                }
            }
            set_config_plugin('blocktype', 'internalmedia', 'enabledtypes', serialize($newdata));
        }
    }

    if ($oldversion < 2009021200) {
        $table = new XMLDBTable('artefact_file_mime_types');
        $key = new XMLDBKey('artefilemimetype_des_fk');
        $key->setAttributes(XMLDB_KEY_FOREIGN, array('description'), 'artefact_file_file_types', array('description'));
        drop_key($table, $key);

        $table = new XMLDBTable('artefact_file_file_types');
        drop_table($table);
        PluginArtefactFile::resync_filetype_list();
    }

    if ($oldversion < 2009021301) {
        // IE has been uploading jpegs with the image/pjpeg mimetype,
        // which is not recognised as an image by the download script.
        // Fix all existing jpegs in the db:
        set_field('artefact_file_files', 'filetype', 'image/jpeg', 'filetype', 'image/pjpeg');
        // This won't happen again because we now read the contents of the
        // uploaded file to detect image artefacts, and overwrite the mime
        // type declared by the browser if we see an image.
    }

    if ($oldversion < 2009033000) {
        if (!get_record('artefact_config', 'plugin', 'file', 'field', 'uploadagreement')) {
            insert_record('artefact_config', (object) array('plugin' => 'file', 'field' => 'uploadagreement', 'value' => 1));
            insert_record('artefact_config', (object) array('plugin' => 'file', 'field' => 'usecustomagreement', 'value' => 1));
        }
    }

    if ($oldversion < 2009091700) {
        execute_sql("DELETE FROM {artefact_file_files} WHERE artefact IN (SELECT id FROM {artefact} WHERE artefacttype = 'folder')");
    }

    if ($oldversion < 2009091701) {
        $table = new XMLDBTable('artefact_file_files');
        $key = new XMLDBKey('artefactpk');
        $key->setAttributes(XMLDB_KEY_PRIMARY, array('artefact'));
        add_key($table, $key);

        $table = new XMLDBTable('artefact_file_image');
        $key = new XMLDBKey('artefactpk');
        $key->setAttributes(XMLDB_KEY_PRIMARY, array('artefact'));
        add_key($table, $key);
    }

    if ($oldversion < 2009092300) {
        insert_record('artefact_installed_type', (object) array('plugin' => 'file', 'name' => 'archive'));
        // update old files
        if (function_exists('zip_open')) {
            $files = get_records_select_array('artefact_file_files', "filetype IN ('application/zip', 'application/x-zip')");
            if ($files) {
                $checked = array();
                foreach ($files as $file) {
                    $path = get_config('dataroot') . 'artefact/file/originals/' . ($file->fileid % 256) . '/' . $file->fileid;
                    $zip = zip_open($path);
                    if (is_resource($zip)) {
                        $checked[] = $file->artefact;
                        zip_close($zip);
                    }
                }
                if (!empty($checked)) {
                    set_field_select('artefact', 'artefacttype', 'archive', "artefacttype = 'file' AND id IN (" . join(',', $checked) . ')', array());
                }
            }
        }
    }

    if ($oldversion < 2010012702) {
        if ($records = get_records_sql_array("SELECT * FROM {artefact_file_files} WHERE filetype='application/octet-stream'", array())) {
            require_once('file.php');
            foreach ($records as &$r) {
                $path = get_config('dataroot') . 'artefact/file/originals/' . $r->fileid % 256 . '/' . $r->fileid;
                set_field('artefact_file_files', 'filetype', file_mime_type($path), 'fileid', $r->fileid, 'artefact', $r->artefact);
            }
        }
    }

    if ($oldversion < 2011052500) {
        // Set default quota to 50MB
        set_config_plugin('artefact', 'file', 'defaultgroupquota', 52428800);
    }

    if ($oldversion < 2011070700) {

        // Create an images folder for everyone with a profile icon
        $imagesdir = get_string('imagesdir', 'artefact.file');
        $imagesdirdesc = get_string('imagesdirdesc', 'artefact.file');

        execute_sql("
            INSERT INTO {artefact} (artefacttype, container, owner, ctime, mtime, atime, title, description, author)
            SELECT 'folder', 1, owner, current_timestamp, current_timestamp, current_timestamp, ?, ?, owner
            FROM {artefact} WHERE owner IS NOT NULL AND artefacttype = 'profileicon'
            GROUP BY owner",
            array($imagesdir, $imagesdirdesc)
        );

        // Put profileicons into the images folder and update the description
        $profileicondesc = get_string('uploadedprofileicon', 'artefact.file');

        if (is_postgres()) {
            execute_sql("
                UPDATE {artefact}
                SET parent = f.folderid, description = ?
                FROM (
                    SELECT owner, MAX(id) AS folderid
                    FROM {artefact}
                    WHERE artefacttype = 'folder' AND title = ? AND description = ?
                    GROUP BY owner
                ) f
                WHERE artefacttype = 'profileicon' AND {artefact}.owner = f.owner",
                array($profileicondesc, $imagesdir, $imagesdirdesc)
            );
        }
        else {
            execute_sql("
                UPDATE {artefact}, (
                    SELECT owner, MAX(id) AS folderid
                    FROM {artefact}
                    WHERE artefacttype = 'folder' AND title = ? AND description = ?
                    GROUP BY owner
                ) f
                SET parent = f.folderid, description = ?
                WHERE artefacttype = 'profileicon' AND {artefact}.owner = f.owner",
                array($imagesdir, $imagesdirdesc, $profileicondesc)
            );
        }
    }

    if ($oldversion < 2011082200) {
        // video file type
        if (!get_record('artefact_installed_type', 'plugin', 'file', 'name', 'video')) {
            insert_record('artefact_installed_type', (object) array('plugin' => 'file', 'name' => 'video'));
        }

        // update existing records
        $videotypes = get_records_sql_array('
            SELECT DISTINCT description
            FROM {artefact_file_mime_types}
            WHERE mimetype ' . db_ilike() . ' \'%video%\'', array());
        if ($videotypes) {
            $mimetypes = array();
            foreach ($videotypes as $type) {
                $mimetypes[] = $type->description;
            }
            $files = get_records_sql_array('
                SELECT *
                FROM {artefact_file_files}
                WHERE filetype IN (
                    SELECT mimetype
                    FROM {artefact_file_mime_types}
                    WHERE description IN (' . join(',', array_map('db_quote', array_values($mimetypes))) . ')
                )', array());
            if ($files) {
                $checked = array();
                foreach ($files as $file) {
                    $checked[] = $file->artefact;
                }
                if (!empty($checked)) {
                    set_field_select('artefact', 'artefacttype', 'video', "artefacttype = 'file' AND id IN (" . join(',', $checked) . ')', array());
                }
            }
        }

        // audio file type
        if (!get_record('artefact_installed_type', 'plugin', 'file', 'name', 'audio')) {
            insert_record('artefact_installed_type', (object) array('plugin' => 'file', 'name' => 'audio'));
        }

        // update existing records
        $audiotypes = get_records_sql_array('
            SELECT DISTINCT description
            FROM {artefact_file_mime_types}
            WHERE mimetype ' . db_ilike() . ' \'%audio%\'', array());
        if ($audiotypes) {
            $mimetypes = array();
            foreach ($audiotypes as $type) {
                $mimetypes[] = $type->description;
            }
            $files = get_records_sql_array('
                SELECT *
                FROM {artefact_file_files}
                WHERE filetype IN (
                    SELECT mimetype
                    FROM {artefact_file_mime_types}
                    WHERE description IN (' . join(',', array_map('db_quote', array_values($mimetypes))) . ')
                 )', array());
             if ($files) {
                 $checked = array();
                 foreach ($files as $file) {
                     $checked[] = $file->artefact;
                 }
                 if (!empty($checked)) {
                     set_field_select('artefact', 'artefacttype', 'audio', "artefacttype = 'file' AND id IN (" . join(',', $checked) . ')', array());
                }
            }
        }
    }

    if ($oldversion < 2012050400) {
        if (!get_record('artefact_config', 'plugin', 'file', 'field', 'resizeonuploadenable')) {
            insert_record('artefact_config', (object) array('plugin' => 'file', 'field' => 'resizeonuploadenable', 'value' => 0));
            insert_record('artefact_config', (object) array('plugin' => 'file', 'field' => 'resizeonuploaduseroption', 'value' => 0));
            insert_record('artefact_config', (object) array('plugin' => 'file', 'field' => 'resizeonuploadmaxheight', 'value' => get_config('imagemaxheight')));
            insert_record('artefact_config', (object) array('plugin' => 'file', 'field' => 'resizeonuploadmaxwidth', 'value' => get_config('imagemaxwidth')));
        }
    }

    if ($oldversion < 2012092400) {
        $basepath = get_config('dataroot') . "artefact/file/originals/";
        try {
            check_dir_exists($basepath, true);
        }
        catch (Exception $e) {
            throw new SystemException("Failed to create " . $basepath);
        }
        $baseiter = new DirectoryIterator($basepath);
        foreach ($baseiter as $dir) {
            if ($dir->isDot()) continue;
            $dirpath = $dir->getPath() . '/' . $dir->getFilename();
            $fileiter = new DirectoryIterator($dirpath);
            foreach ($fileiter as $file) {
                if ($file->isDot()) continue;
                if (!$file->isFile()) {
                    log_error("Something was wrong about the dataroot in artefact/file/originals/$dir. Unexpected folder $file");
                    continue;
                }
                chmod($file->getPathname(), $file->getPerms() & 0666);
            }
        }
    }

    if ($oldversion < 2013031200) {
        // Update MIME types for Microsoft video files: avi, asf, wm, and wmv
        update_record('artefact_file_mime_types', (object) array('mimetype' => 'video/x-ms-asf', 'description' => 'asf'), (object) array('mimetype' => 'video/x-ms-asf'));
        update_record('artefact_file_mime_types', (object) array('mimetype' => 'video/x-ms-wm', 'description' => 'wm'), (object) array('mimetype' => 'video/x-ms-wm'));
        update_record('artefact_file_mime_types', (object) array('mimetype' => 'video/x-ms-wmv', 'description' => 'wmv'), (object) array('mimetype' => 'video/x-ms-wmv'));
    }

    if ($oldversion < 2014040800) {
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'audio/aac'), (object) array('mimetype' => 'audio/aac', 'description' => 'aac'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/msaccess'), (object) array('mimetype' => 'application/msaccess', 'description' => 'accdb'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'shockwave/director'), (object) array('mimetype' => 'shockwave/director', 'description' => 'cct'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-csh'), (object) array('mimetype' => 'application/x-csh', 'description' => 'cs'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'text/css'), (object) array('mimetype' => 'text/css', 'description' => 'css'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'text/csv'), (object) array('mimetype' => 'text/csv', 'description' => 'csv'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'video/x-dv'), (object) array('mimetype' => 'video/x-dv', 'description' => 'dv'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'description' => 'docx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-word.document.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-word.document.macroEnabled.12', 'description' => 'docm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'description' => 'dotx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-word.template.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-word.template.macroEnabled.12', 'description' => 'dotm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-director'), (object) array('mimetype' => 'application/x-director', 'description' => 'dcr'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/epub+zip'), (object) array('mimetype' => 'application/epub+zip', 'description' => 'epub'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-smarttech-notebook'), (object) array('mimetype' => 'application/x-smarttech-notebook', 'description' => 'gallery'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/mac-binhex40'), (object) array('mimetype' => 'application/mac-binhex40', 'description' => 'hqx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'text/x-component'), (object) array('mimetype' => 'text/x-component', 'description' => 'htc'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/xhtml+xml'), (object) array('mimetype' => 'application/xhtml+xml', 'description' => 'xhtml'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'image/vnd.microsoft.icon'), (object) array('mimetype' => 'image/vnd.microsoft.icon', 'description' => 'ico'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'text/calendar'), (object) array('mimetype' => 'text/calendar', 'description' => 'ics'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/inspiration'), (object) array('mimetype' => 'application/inspiration', 'description' => 'isf'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/inspiration.template'), (object) array('mimetype' => 'application/inspiration.template', 'description' => 'ist'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/java-archive'), (object) array('mimetype' => 'application/java-archive', 'description' => 'jar'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-java-jnlp-file'), (object) array('mimetype' => 'application/x-java-jnlp-file', 'description' => 'jnlp'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.moodle.backup'), (object) array('mimetype' => 'application/vnd.moodle.backup', 'description' => 'mbz'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-msaccess'), (object) array('mimetype' => 'application/x-msaccess', 'description' => 'mdb'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'message/rfc822'), (object) array('mimetype' => 'message/rfc822', 'description' => 'mht'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.moodle.profiling'), (object) array('mimetype' => 'application/vnd.moodle.profiling', 'description' => 'mpr'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.oasis.opendocument.graphics-template'), (object) array('mimetype' => 'application/vnd.oasis.opendocument.graphics-template', 'description' => 'otg'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.oasis.opendocument.presentation-template'), (object) array('mimetype' => 'application/vnd.oasis.opendocument.presentation-template', 'description' => 'otp'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.oasis.opendocument.spreadsheet-template'), (object) array('mimetype' => 'application/vnd.oasis.opendocument.spreadsheet-template', 'description' => 'ots'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'audio/ogg'), (object) array('mimetype' => 'audio/ogg', 'description' => 'oga'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'video/ogg'), (object) array('mimetype' => 'video/ogg', 'description' => 'ogv'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'image/pict'), (object) array('mimetype' => 'image/pict', 'description' => 'pct'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'description' => 'pptx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12', 'description' => 'pptm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.template'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.template', 'description' => 'potx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-powerpoint.template.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-powerpoint.template.macroEnabled.12', 'description' => 'potm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-powerpoint.addin.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-powerpoint.addin.macroEnabled.12', 'description' => 'ppam'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'description' => 'ppsx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12', 'description' => 'ppsm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'audio/x-realaudio-plugin'), (object) array('mimetype' => 'audio/x-realaudio-plugin', 'description' => 'ra'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'audio/x-pn-realaudio-plugin'), (object) array('mimetype' => 'audio/x-pn-realaudio-plugin', 'description' => 'ram'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.rn-realmedia-vbr'), (object) array('mimetype' => 'application/vnd.rn-realmedia-vbr', 'description' => 'rmvb'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'text/richtext'), (object) array('mimetype' => 'text/richtext', 'description' => 'rtx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-stuffit'), (object) array('mimetype' => 'application/x-stuffit', 'description' => 'sit'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/smil'), (object) array('mimetype' => 'application/smil', 'description' => 'smi'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'image/svg+xml'), (object) array('mimetype' => 'image/svg+xml', 'description' => 'svg'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.writer'), (object) array('mimetype' => 'application/vnd.sun.xml.writer', 'description' => 'sxw'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.writer.template'), (object) array('mimetype' => 'application/vnd.sun.xml.writer.template', 'description' => 'stw'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.calc'), (object) array('mimetype' => 'application/vnd.sun.xml.calc', 'description' => 'sxc'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.calc.template'), (object) array('mimetype' => 'application/vnd.sun.xml.calc.template', 'description' => 'stc'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.draw'), (object) array('mimetype' => 'application/vnd.sun.xml.draw', 'description' => 'sxd'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.draw.template'), (object) array('mimetype' => 'application/vnd.sun.xml.draw.template', 'description' => 'std'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.impress'), (object) array('mimetype' => 'application/vnd.sun.xml.impress', 'description' => 'sxi'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.impress.template'), (object) array('mimetype' => 'application/vnd.sun.xml.impress.template', 'description' => 'sti'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.writer.global'), (object) array('mimetype' => 'application/vnd.sun.xml.writer.global', 'description' => 'sxg'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.sun.xml.math'), (object) array('mimetype' => 'application/vnd.sun.xml.math', 'description' => 'sxm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'image/tiff'), (object) array('mimetype' => 'image/tiff', 'description' => 'tif'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-tex'), (object) array('mimetype' => 'application/x-tex', 'description' => 'tex'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/x-texinfo'), (object) array('mimetype' => 'application/x-texinfo', 'description' => 'texi'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'text/tab-separated-values'), (object) array('mimetype' => 'text/tab-separated-values', 'description' => 'tsv'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'video/webm'), (object) array('mimetype' => 'video/webm', 'description' => 'webm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-excel'), (object) array('mimetype' => 'application/vnd.ms-excel', 'description' => 'xls'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'description' => 'xlsx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-excel.sheet.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-excel.sheet.macroEnabled.12', 'description' => 'xlsm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template'), (object) array('mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'description' => 'xltx'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-excel.template.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-excel.template.macroEnabled.12', 'description' => 'xltm'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12', 'description' => 'xlsb'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/vnd.ms-excel.addin.macroEnabled.12'), (object) array('mimetype' => 'application/vnd.ms-excel.addin.macroEnabled.12', 'description' => 'xlam'));
        ensure_record_exists('artefact_file_mime_types', (object) array('mimetype' => 'application/xml'), (object) array('mimetype' => 'application/xml', 'description' => 'xml'));
    }

    if ($oldversion < 2014041400) {
        $events = array(
            (object)array(
                'plugin'        => 'file',
                'event'         => 'saveartefact',
                'callfunction'  => 'eventlistener_savedeleteartefact',
            ),
            (object)array(
                'plugin'        => 'file',
                'event'         => 'deleteartefact',
                'callfunction'  => 'eventlistener_savedeleteartefact',
            ),
            (object)array(
                'plugin'        => 'file',
                'event'         => 'deleteartefacts',
                'callfunction'  => 'eventlistener_savedeleteartefact',
            ),
            (object)array(
                'plugin'        => 'file',
                'event'         => 'updateuser',
                'callfunction'  => 'eventlistener_savedeleteartefact',
            ),
        );
        foreach ($events as $event) {
            ensure_record_exists('artefact_event_subscription', $event, $event);
        }
        PluginArtefactFile::set_quota_triggers();
    }

    if ($oldversion < 2014051200) {
        require_once(get_config('docroot') . '/lib/file.php');
        $mimetypes = get_records_assoc('artefact_file_mime_types', '', '', '', 'description,mimetype');

        // Re-examine only those files where their current identified mimetype is
        // different from how we would identify their mimetype based on file extension
        $rs = get_recordset_sql('
            select a.id, aff.oldextension, aff.filetype
            from
                {artefact} a
                inner join {artefact_file_files} aff
                    on a.id = aff.artefact
            where a.artefacttype = \'archive\'
                and not exists (
                    select 1 from {artefact_file_mime_types} afmt
                    where afmt.description = aff.oldextension
                    and afmt.mimetype = aff.filetype
                )
            order by a.id
        ');

        $total = 0;
        $done = 0;

        while ($zf = $rs->FetchRow()) {
            if ($done % 100 == 0) {
                log_debug('Verifying filetypes: ' . $done . '/' . $rs->RecordCount());
            }
            $done++;
            $file = artefact_instance_from_id($zf['id']);
            $path = $file->get_path();

            // Check what our improved file detection system thinks it is
            $guess = file_mime_type($path, 'foo.' . $zf['oldextension']);

            if ($guess != 'application/octet-stream') {
                $data = new stdClass();
                $data->filetype = $data->guess = $guess;
                foreach (array('video', 'audio', 'archive') as $artefacttype) {
                    $classname = 'ArtefactType' . ucfirst($artefacttype);
                    if (call_user_func_array(array($classname, 'is_valid_file'), array($file->get_path(), &$data))) {
                        set_field('artefact', 'artefacttype', $artefacttype, 'id', $zf['id']);
                        set_field('artefact_file_files', 'filetype', $data->filetype, 'artefact', $zf['id']);
                        continue 2;
                    }
                }

                // It wasn't any of those special ones, so just make it a normal file artefact
                set_field('artefact', 'artefacttype', 'file', 'id', $zf['id']);
                set_field('artefact_file_files', 'filetype', $data->filetype, 'artefact', $zf['id']);
            }
        }
        log_debug('Verifying filetypes: ' . $done . '/' . $rs->RecordCount());
        $rs->Close();
    }

    return $status;
}
