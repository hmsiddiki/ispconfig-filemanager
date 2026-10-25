# Tidies expired temporary FTP accounts made by the ISPConfig file manager (installed by install.sh)
*/10 * * * * @PANEL_USER@ @PHP_BIN@ @FM_HOME@/bin/cleanup.php >/dev/null 2>&1
