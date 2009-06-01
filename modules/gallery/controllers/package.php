<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Package_Controller extends Controller {
  function index() {
    if (PHP_SAPI != 'cli') {
      Kohana::show_404();
    }

    $this->auto_render = false;

    $this->_reset();                // empty and reinstall the standard modules

    $this->_dump_database();        // Dump the database

    $this->_dump_var();             // Dump the var directory
    print t("Successfully wrote install.sql and init_var.php\n");
  }

  private function _reset() {
    $db = Database::instance();

    // Drop all tables
    foreach ($db->list_tables() as $table) {
      $db->query("DROP TABLE IF EXISTS `$table`");
    }

    // Clean out data
    dir::unlink(VARPATH . "uploads");
    dir::unlink(VARPATH . "albums");
    dir::unlink(VARPATH . "resizes");
    dir::unlink(VARPATH . "thumbs");
    dir::unlink(VARPATH . "modules");
    dir::unlink(VARPATH . "tmp");

    $db->clear_cache();
    module::$modules = array();
    module::$active = array();

    // Use a known random seed so that subsequent packaging runs will reuse the same random
    // numbers, keeping our install.sql file more stable.
    srand(0);

    try {
      gallery_installer::install(true);
      module::load_modules();

      foreach (array("user", "comment", "organize", "info", "rss",
                     "search", "slideshow", "tag") as $module_name) {
        module::install($module_name);
        module::activate($module_name);
      }
    } catch (Exception $e) {
      Kohana::log("error", $e->getTraceAsString());
      print $e->getTrace();
      throw $e;
    }
  }

  private function _dump_database() {
    // We now have a clean install with just the packages that we want.  Make sure that the
    // database is clean too.
    $db = Database::instance();
    $db->query("TRUNCATE {sessions}");
    $db->query("TRUNCATE {logs}");
    $db->query("DELETE FROM {vars} WHERE `module_name` = 'core' AND `name` = '_cache'");
    $db->update("users", array("password" => ""), array("id" => 1));
    $db->update("users", array("password" => ""), array("id" => 2));

    $dbconfig = Kohana::config('database.default');
    $conn = $dbconfig["connection"];
    $pass = $conn["pass"] ? "-p{$conn['pass']}" : "";
    $sql_file = DOCROOT . "installer/install.sql";
    if (!is_writable($sql_file)) {
      print "$sql_file is not writeable";
      return;
    }
    $command = "mysqldump --compact --add-drop-table -h{$conn['host']} " .
      "-u{$conn['user']} $pass {$conn['database']} > $sql_file";
    exec($command, $output, $status);
    if ($status) {
      print "<pre>";
      print "$command\n";
      print "Failed to dump database\n";
      print implode("\n", $output);
      return;
    }

    // Post-process the sql file
    $buf = "";
    $root_timestamp = ORM::factory("item", 1)->created;
    foreach (file($sql_file) as $line) {
      // Prefix tables
      $line = preg_replace(
        "/(CREATE TABLE|IF EXISTS|INSERT INTO) `{$dbconfig['table_prefix']}(\w+)`/", "\\1 {\\2}",
        $line);

      // Normalize dates
      $line = preg_replace("/,$root_timestamp,/", ",UNIX_TIMESTAMP(),", $line);
      $buf .= $line;
    }
    $fd = fopen($sql_file, "wb");
    fwrite($fd, $buf);
    fclose($fd);
  }

  private function _dump_var() {
    $this->auto_render = false;

    $objects = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator(VARPATH),
      RecursiveIteratorIterator::SELF_FIRST);

    $var_file = DOCROOT . "installer/init_var.php";
    if (!is_writable($var_file)) {
      print "$var_file is not writeable";
      return;
    }

    $paths = array();
    foreach($objects as $name => $file){
      if ($file->getBasename() == "database.php") {
        continue;
      } else if (basename($file->getPath()) == "logs") {
        continue;
      }

      if ($file->isDir()) {
        $paths[] = "VARPATH . \"" . substr($name, strlen(VARPATH)) . "\"";
      } else {
        // @todo: serialize non-directories
        print "Unknown file: $name";
        return;
      }
    }
    // Sort the paths so that the var file is stable
    sort($paths);

    $fd = fopen($var_file, "w");
    fwrite($fd, "<?php defined(\"SYSPATH\") or die(\"No direct script access.\") ?>\n");
    fwrite($fd, "<?php\n");
    foreach ($paths as $path) {
      fwrite($fd, "!file_exists($path) && mkdir($path);\n");
    }
    fclose($fd);
  }
}