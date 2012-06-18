<?php if (!defined('APPLICATION')) exit();

/**
 * Contains functions for combining javascript and css files.
 * 
 * Events:
 * - AssetModel_StyleCss_Handler(...)
 *
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Garden
 * @since 2.1
 */

class AssetModel extends Gdn_Model {
   protected $_CssFiles = array();
   
   public function AddCssFile($Filename, $Folder = FALSE) {
      $this->_CssFiles[] = array($Filename, $Folder);
   }
   
   public function ServeCss($Basename, $Revision) {
      $Basename = ucfirst($Basename);
      
      header_remove('Set-Cookie');
      header("Content-Type: text/css");
      if (!in_array($Basename, array('Style'))) {
         header("HTTP/1.0 404", TRUE, 404);
         
         echo "/* Could not find $Basename/$Revision */";
         die();
      }
      
      $ETags = GetValue('HTTP_IF_NONE_MATCH', $_SERVER);
      if (get_magic_quotes_gpc())
         $ETags = stripslashes($ETags);
      $ETags = explode(',', $ETags);
      foreach ($ETags as $eTag) {
         if ($eTag == $Revision) {
            header("HTTP/1.0 304", TRUE, 304);
            die();
         }
      }
      
      header("Cache-Control:public, max-age=14400");
            
      // Include minify...
      set_include_path(PATH_LIBRARY."/vendors/Minify/lib".PATH_SEPARATOR.get_include_path());
      require_once PATH_LIBRARY."/vendors/Minify/lib/Minify/CSS.php";
      
      $CurrentETag = self::ETag();
      header("ETag: $CurrentETag");
      echo "/* CSS generated for etag: $CurrentETag.\n *\n";
      
      
      $Paths = $this->GetCssFiles($Basename, $NotFound);
      
      // First, do a pass through the files to generate some information.
      foreach ($Paths as $Info) {
         list($Path, $UrlPath) = $Info;
         
         echo " * $UrlPath\n";
      }
      
      // Echo the paths that weren't found to help debugging.
      foreach ($NotFound as $Info) {
         list($Filename, $Folder) = $Info;
         
         echo " * $Folder/$Filename NOT FOUND.\n";
      }
      
      echo " */\n\n";
      
      // Now that we have all of the paths we want to serve them.
      foreach ($Paths as $Info) {
         list($Path, $UrlPath) = $Info;
         echo "/* File: $UrlPath */\n";

         $Css = file_get_contents($Path);
         $Css = Minify_CSS::minify($Css, array(
               'preserveComments' => TRUE,
               'prependRelativePath' => $UrlPath,
               'currentDir' => dirname($Path),
               'minify' => TRUE
         ));
         echo $Css;

         echo "\n\n";
      }
   }
   
   public function GetCssFiles($Basename, &$NotFound) {
      $NotFound = array();
      
      // Gather all of the css paths.
      switch ($Basename) {
         case 'Style':
            $this->_CssFiles = array(
               array('style.css', 'dashboard'));
            break;
         case 'Admin':
            $this->_CssFiles = array(
                array('admin.css'));
            break;
         default:
            $this->_CssFiles = array();
      }
      
            // Throw an event so that plugins can add their css too.
      $this->FireEvent($Basename.'Css');
      
      // Include theme customizations last so that they override everything else.
      switch ($Basename) {
         case 'Style':
            $this->AddCssFile('custom.css');
            
            if (Gdn::Controller()->Theme && Gdn::Controller()->ThemeOptions) {
               $Filenames = GetValueR('Styles.Value', $this->ThemeOptions);
               if (is_string($Filenames) && $Filenames != '%s')
                  $this->AddCssFile(array(ChangeBasename('custom.css', $Filenames)));
            }
            
            break;
         case 'Admin':
            $this->AddCssFile('customadmin.css');
            break;
      }
      
      // Hunt the css files down.
      $Paths = array();
      foreach ($this->_CssFiles as $Info) {
         $Filename = $Info[0];
         $Folder = GetValue(1, $Info);
         
         list($Path, $UrlPath) = self::CssPath($Filename, $Folder);
         if ($Path) {
            $Paths[] = array($Path, $UrlPath);
         } else {
            $NotFound = array($Filename, $Folder);
         }
      }
      
      return $Paths;
   }
   
   public static function CssPath($Filename, $Folder) {
      // 1. Check for a fill path.
      if (strpos($Filename, '/') !== FALSE) {
         $Filename = '/'.ltrim($Filename, '/');
         $Path = PATH_ROOT.$Filename;
         if (file_exists($Path))
            return array($Path, $Filename);
         else
            return FALSE;
      }
      
      // 2. Check the theme.
      if ($Theme = Gdn::Controller()->Theme) {
         $Paths[] = array(PATH_THEMES."/$Theme/design/$Filename", "/themes/$Theme/design/$Filename");
      }
      
      if ($Folder) {
         // 3. Check a plugin or application.
         if (StringBeginsWith($Folder, 'plugins/')) {
            $Folder = substr($Folder, strlen('plugins/'));
            $Paths[] = array(PATH_PLUGINS."/$Folder/design/$Filename", "/plugins/$Folder/design/$Filename");
            $Paths[] = array(PATH_PLUGINS."/$Folder/$Filename", "/plugins/$Folder/$Filename");
         } else {
            $Paths[] = array(PATH_APPLICATIONS."/$Folder/design/$Filename", "/applications/$Folder/design/$Filename");
         }
      }
      
      // 4. Check the default.
      if ($Folder != 'dashboard')
         $Paths[] = array(PATH_APPLICATIONS.'/dashboard/design/$Filename', "/applications/dashboard/design/$Filename");
      
      foreach ($Paths as $Info) {
         if (file_exists($Info[0]))
            return $Info;
      }
      
      return FALSE;
   }
   
   /** Generate an e-tag for the application from the versions of all of its enabled applications/plugins. **/
   public static function ETag() {
      $Data = array();
      $Data['vanilla-core-'.APPLICATION_VERSION] = TRUE;
      
      $Plugins = Gdn::PluginManager()->EnabledPlugins();
      foreach ($Plugins as $Info) {
         $Data[strtolower("{$Info['Index']}-plugin-{$Info['Version']}")] = TRUE;
      }
//      echo(Gdn_Upload::FormatFileSize(strlen(serialize($Plugins))));
//      decho($Plugins);
      
      $Applications = Gdn::ApplicationManager()->EnabledApplications();
      foreach ($Applications as $Info) {
         $Data[strtolower("{$Info['Index']}-app-{$Info['Version']}")] = TRUE;
      }
      
      $Info = Gdn::ThemeManager()->EnabledThemeInfo();
      if (!empty($Info)) {
         $Data[strtolower("{$Info['Index']}-theme-{$Info['Version']}")] = TRUE;
//         decho($Theme);
//         die();
      }
      
      // TODO: Add an event for plugins to add stuff to the e-tag.
      
      ksort($Data);
      $Result = substr(md5(implode(',', array_keys($Data))), 0, 8);
//      decho($Data);
//      die();
      return $Result;
   }
}