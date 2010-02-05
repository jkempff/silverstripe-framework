<?php
/**
 * The SSViewer executes a .ss template file.
 * The SSViewer class handles rendering of .ss templates.  In addition to a full template in
 * the views folder, a template in views/Content or views/Layout will be rendered into $Content and
 * $Layout, respectively.
 *
 * Compiled templates are cached.  If you put ?flush=1 on your URL, it will force the template to be recompiled.  This
 * is a hack; the system should really detect when a page needs re-fetching.
 * 
 * Works with the global $_TEMPLATE_MANIFEST which is compiled by {@link ManifestBuilder->getTemplateManifest()}.
 * This associative array lists all template filepaths by "identifier", meaning the name
 * of the template without its path or extension.
 * 
 * Example:
 * <code>
 * array(
 *  'LeftAndMain' => 
 *  array (
 * 	'main' => '/my/system/path/cms/templates/LeftAndMain.ss',
 *  ),
 * 'CMSMain_left' => 
 *   array (
 *     'Includes' => '/my/system/path/cms/templates/Includes/CMSMain_left.ss',
 *   ),
 * 'Page' => 
 *   array (
 *     'themes' => 
 *     array (
 *       'blackcandy' => 
 *       array (
 *         'Layout' => '/my/system/path/themes/blackcandy/templates/Layout/Page.ss',
 *         'main' => '/my/system/path/themes/blackcandy/templates/Page.ss',
 *       ),
 *       'blue' => 
 *       array (
 *         'Layout' => '/my/system/path/themes/mysite/templates/Layout/Page.ss',
 *         'main' => '/my/system/path/themes/mysite/templates/Page.ss',
 *       ),
 *     ),
 *   ),
 *   // ...
 * )
 * </code>
 *
 * @todo Fix the broken caching.
 * @package sapphire
 * @subpackage view
 */
class SSViewer {
	
	/**
	 * @var boolean $source_file_comments
	 */
	protected static $source_file_comments = false;
	
	/**
	 * Set whether HTML comments indicating the source .SS file used to render this page should be
	 * included in the output.  This is enabled by default
	 *
	 * @param boolean $val
	 */
	static function set_source_file_comments($val) {
		self::$source_file_comments = $val;
	}
	
	/**
	 * @return boolean
	 */
	static function get_source_file_comments() {
		return self::$source_file_comments;
	}
	
	/**
	 * @var array $chosenTemplates Associative array for the different
	 * template containers: "main" and "Layout".
	 */
	private $chosenTemplates = array();
	
	/**
	 * @var boolean
	 */
	protected $rewriteHashlinks = true;
	
	/**
	 * @var string
	 */
	protected static $current_theme = null;
	
	/**
	 * Create a template from a string instead of a .ss file
	 * 
	 * @return SSViewer
	 */
	static function fromString($content) {
		return new SSViewer_FromString($content);
	}
	
	/**
	 * @param string $theme
	 */
	static function set_theme($theme) {
		self::$current_theme = $theme;
	}
	
	/**
	 * @return string 
	 */
	static function current_theme() {
		return self::$current_theme;
	}
	
	/**
	 * Pass the SilverStripe template to be used.
	 * 
	 * @param string|array $templateList
	 *   If passed as a string with .ss extension, used as the "main" template
	 */
	public function __construct($templateList) {
		global $_TEMPLATE_MANIFEST;
		
		// flush template manifest cache if requested
		if (isset($_GET['flush']) && $_GET['flush'] == 'all') {
			if(Director::isDev() || Permission::check('ADMIN')) {
				self::flush_template_cache();
			} else {
				return Security::permissionFailure(null, 'Please log in as an administrator to flush the template cache.');
			}
		}
		
		if(substr((string) $templateList,-3) == '.ss') {
			$this->chosenTemplates['main'] = $templateList;
		} else {
			if(!is_array($templateList)) $templateList = array($templateList);
			
			if(isset($_GET['debug_request'])) Debug::message("Selecting templates from the following list: " . implode(", ", $templateList));

			foreach($templateList as $template) {
				// if passed as a partial directory (e.g. "Layout/Page"), split into folder and template components
				if(strpos($template,'/') !== false) list($templateFolder, $template) = explode('/', $template, 2);
				else $templateFolder = null;

				// Use the theme template if available
				if(self::current_theme() && isset($_TEMPLATE_MANIFEST[$template]['themes'][self::current_theme()])) {
					$this->chosenTemplates = array_merge(
						$_TEMPLATE_MANIFEST[$template]['themes'][self::current_theme()], 
						$this->chosenTemplates
					);
					
					if(isset($_GET['debug_request'])) Debug::message("Found template '$template' from main theme '" . self::current_theme() . "': " . var_export($_TEMPLATE_MANIFEST[$template]['themes'][self::current_theme()], true));
				}
				
				// Fall back to unthemed base templates
				if(isset($_TEMPLATE_MANIFEST[$template]) && (array_keys($_TEMPLATE_MANIFEST[$template]) != array('themes'))) {
					$this->chosenTemplates = array_merge(
						$_TEMPLATE_MANIFEST[$template], 
						$this->chosenTemplates
					);
					
					if(isset($_GET['debug_request'])) Debug::message("Found template '$template' from main template archive, containing the following items: " . var_export($_TEMPLATE_MANIFEST[$template], true));
					
					unset($this->chosenTemplates['themes']);
				}

				if($templateFolder) {
					$this->chosenTemplates['main'] = $this->chosenTemplates[$templateFolder];
					unset($this->chosenTemplates[$templateFolder]);
				}
			}

			if(isset($_GET['debug_request'])) Debug::message("Final template selections made: " . var_export($this->chosenTemplates, true));

		}

		if(!$this->chosenTemplates) user_error("None of these templates can be found in theme '"
			. self::current_theme() . "': ". implode(".ss, ", $templateList) . ".ss", E_USER_WARNING);
	}
	
	/**
	 * Returns true if at least one of the listed templates exists
	 */
	static function hasTemplate($templateList) {
		if(!is_array($templateList)) $templateList = array($templateList);
	
		global $_TEMPLATE_MANIFEST;
		foreach($templateList as $template) {
			if(strpos($template,'/') !== false) list($templateFolder, $template) = explode('/', $template, 2);
			if(isset($_TEMPLATE_MANIFEST[$template])) return true;
		}
		
		return false;
	}
	
	/**
	 * Set a global rendering option.
	 * The following options are available:
	 *  - rewriteHashlinks: If true (the default), <a href="#..."> will be rewritten to contain the 
	 *    current URL.  This lets it play nicely with our <base> tag.
	 *  - If rewriteHashlinks = 'php' then, a piece of PHP script will be inserted before the hash 
	 *    links: "<?php echo $_SERVER['REQUEST_URI']; ?>".  This is useful if you're generating a 
	 *    page that will be saved to a .php file and may be accessed from different URLs.
	 */
	public static function setOption($optionName, $optionVal) {
		SSViewer::$options[$optionName] = $optionVal;
	}
	protected static $options = array(
		'rewriteHashlinks' => true,
	);
    
	protected static $topLevel = array();
	public static function topLevel() {
		if(SSViewer::$topLevel) {
			return SSViewer::$topLevel[sizeof(SSViewer::$topLevel)-1];
		}
	}
	
	/**
	 * Call this to disable rewriting of <a href="#xxx"> links.  This is useful in Ajax applications.
	 * It returns the SSViewer objects, so that you can call new SSViewer("X")->dontRewriteHashlinks()->process();
	 */
	public function dontRewriteHashlinks() {
		$this->rewriteHashlinks = false;
		self::$options['rewriteHashlinks'] = false;
		return $this;
	}
	
	public function exists() {
		return $this->chosenTemplates;
	}
	
	/**
	 * Searches for a template name in the current theme:
	 * - themes/mytheme/templates
	 * - themes/mytheme/templates/Includes
	 * Falls back to unthemed template files.
	 * 
	 * Caution: Doesn't search in any /Layout folders.
	 * 
	 * @param string $identifier A template name without '.ss' extension or path.
	 * @return string Full system path to a template file
	 */
	public static function getTemplateFile($identifier) {
		global $_TEMPLATE_MANIFEST;
		
		$includeTemplateFile = self::getTemplateFileByType($identifier, 'Includes');
		if($includeTemplateFile) return $includeTemplateFile;
		
		$mainTemplateFile = self::getTemplateFileByType($identifier, 'main');
		if($mainTemplateFile) return $mainTemplateFile;
		
		return false;
	}
	
	/**
	 * @param string $identifier A template name without '.ss' extension or path
	 * @param string $type The template type, either "main", "Includes" or "Layout"
	 * @return string Full system path to a template file
	 */
	public static function getTemplateFileByType($identifier, $type) {
		global $_TEMPLATE_MANIFEST;
		if(self::current_theme() && isset($_TEMPLATE_MANIFEST[$identifier]['themes'][self::current_theme()][$type])) {
			return $_TEMPLATE_MANIFEST[$identifier]['themes'][self::current_theme()][$type];
		} else if(isset($_TEMPLATE_MANIFEST[$identifier][$type])){
			return $_TEMPLATE_MANIFEST[$identifier][$type];
		} else {
			return false;
		}
	}
	
	/**
	 * Used by <% include Identifier %> statements to get the full
	 * unparsed content of a template file.
	 * 
	 * @uses getTemplateFile()
	 * @param string $identifier A template name without '.ss' extension or path.
	 * @return string content of template
	 */
	public static function getTemplateContent($identifier) {
		if(!SSViewer::getTemplateFile($identifier)) {
			return null;
		}
		
		$content = file_get_contents(SSViewer::getTemplateFile($identifier));

		// $content = "<!-- getTemplateContent() :: identifier: $identifier -->". $content; 
		// Adds an i18n namespace to all <% _t(...) %> calls without an existing one
		// to avoid confusion when using the include in different contexts.
		// Entities without a namespace are deprecated, but widely used.
		$content = ereg_replace('<' . '% +_t\((\'([^\.\']*)\'|"([^\."]*)")(([^)]|\)[^ ]|\) +[^% ])*)\) +%' . '>', '<?= _t(\''. $identifier . '.ss' . '.\\2\\3\'\\4) ?>', $content);

		// Remove UTF-8 byte order mark
		// This is only necessary if you don't have zend-multibyte enabled.
		if(substr($content, 0,3) == pack("CCC", 0xef, 0xbb, 0xbf)) {
			$content = substr($content, 3);
		}

		return $content;
	}
	
	/**
	 * @ignore
	 */
	static private $flushed = false;
	
	/**
	 * Clears all parsed template files in the cache folder.
	 *
	 * Can only be called once per request (there may be multiple SSViewer instances).
	 */
	static function flush_template_cache() {
		if (!self::$flushed) {
			$dir = dir(TEMP_FOLDER);
			while (false !== ($file = $dir->read())) {
				if (strstr($file, '.cache')) { unlink(TEMP_FOLDER.'/'.$file); }
			}
			self::$flushed = true;
		}
	}
	
	/**
	 * The process() method handles the "meat" of the template processing.
	 */
	public function process($item, $cache = null) {
		SSViewer::$topLevel[] = $item;
		
		if (!$cache) $cache = SS_Cache::factory('cacheblock');
		
		if(isset($this->chosenTemplates['main'])) {
			$template = $this->chosenTemplates['main'];
		} else {
			$template = $this->chosenTemplates[ reset($dummy = array_keys($this->chosenTemplates)) ];
		}
		
		if(isset($_GET['debug_profile'])) Profiler::mark("SSViewer::process", " for $template");
		$cacheFile = TEMP_FOLDER . "/.cache" . str_replace(array('\\','/',':'),'.',realpath($template));

		$lastEdited = filemtime($template);

		if(!file_exists($cacheFile) || filemtime($cacheFile) < $lastEdited || isset($_GET['flush'])) {
			if(isset($_GET['debug_profile'])) Profiler::mark("SSViewer::process - compile", " for $template");
			
			$content = file_get_contents($template);
			$content = SSViewer::parseTemplateContent($content, $template);
			
			$fh = fopen($cacheFile,'w');
			fwrite($fh, $content);
			fclose($fh);

			if(isset($_GET['debug_profile'])) Profiler::unmark("SSViewer::process - compile", " for $template");
		}
	
		
		if(isset($_GET['showtemplate']) && !Director::isLive()) {
			$lines = file($cacheFile);
			echo "<h2>Template: $cacheFile</h2>";
			echo "<pre>";
			foreach($lines as $num => $line) {
				echo str_pad($num+1,5) . htmlentities($line);
			}
			echo "</pre>";
		}
		
		
		foreach(array('Content', 'Layout') as $subtemplate) {
			if(isset($this->chosenTemplates[$subtemplate])) {
				$subtemplateViewer = new SSViewer($this->chosenTemplates[$subtemplate]);
				$item = $item->customise(array(
					$subtemplate => $subtemplateViewer->process($item, $cache)
				));
			}
		}
		
		$itemStack = array();
		$val = "";
		$valStack = array();
		
		include($cacheFile);

		$output = $val;		
		$output = Requirements::includeInHTML($template, $output);
		
		array_pop(SSViewer::$topLevel);

		if(isset($_GET['debug_profile'])) Profiler::unmark("SSViewer::process", " for $template");
		
		// If we have our crazy base tag, then fix # links referencing the current page.
		if($this->rewriteHashlinks && self::$options['rewriteHashlinks']) {
			if(strpos($output, '<base') !== false) {
				if(SSViewer::$options['rewriteHashlinks'] === 'php') { 
					$thisURLRelativeToBase = "<?php echo \$_SERVER['REQUEST_URI']; ?>"; 
				} else { 
					$thisURLRelativeToBase = Director::makeRelative(Director::absoluteURL($_SERVER['REQUEST_URI'])); 
				}
				$output = preg_replace('/(<a[^>]+href *= *)"#/i', '\\1"' . $thisURLRelativeToBase . '#', $output);
			}
		}

		return $output;
	}

	static function parseTemplateContent($content, $template="") {			
		// Remove UTF-8 byte order mark:
		// This is only necessary if you don't have zend-multibyte enabled.
		if(substr($content, 0,3) == pack("CCC", 0xef, 0xbb, 0xbf)) {
			$content = substr($content, 3);
		}

		// Add template filename comments on dev sites
		if(Director::isDev() && self::$source_file_comments && $template && stripos($content, "<?xml") === false) {
			// If this template is a full HTML page, then put the comments just inside the HTML tag to prevent any IE glitches
			if(stripos($content, "<html") !== false) {
				$content = preg_replace('/(<html[^>]*>)/i', "\\1<!-- template $template -->", $content);
				$content = preg_replace('/(<\/html[^>]*>)/i', "\\1<!-- end template $template -->", $content);
			} else {
				$content = "<!-- template $template -->\n" . $content . "\n<!-- end template $template -->";
			}
		}
		
		while(true) {
			$oldContent = $content;
			
			// Add include filename comments on dev sites
			if(Director::isDev() && self::$source_file_comments) $replacementCode = 'return "<!-- include " . SSViewer::getTemplateFile($matches[1]) . " -->\n" 
				. SSViewer::getTemplateContent($matches[1]) 
				. "\n<!-- end include " . SSViewer::getTemplateFile($matches[1]) . " -->";';
			else $replacementCode = 'return SSViewer::getTemplateContent($matches[1]);';
			
			$content = preg_replace_callback('/<' . '% include +([A-Za-z0-9_]+) +%' . '>/', create_function(
				'$matches', $replacementCode
				), $content);
			if($oldContent == $content) break;
		}
		
		// $val, $val.property, $val(param), etc.
		$replacements = array(
			'/<%--.*--%>/U' =>  '',
			'/\$Iteration/' =>  '<?= {dlr}key ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+), *([^),]+)\\)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)}/' => '<?= {dlr}item->obj("\\1",array("\\2","\\3"),true)->obj("\\4",null,true)->XML_val("\\5",null,true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+), *([^),]+)\\)\\.([A-Za-z0-9_]+)}/' => '<?= {dlr}item->obj("\\1",array("\\2","\\3"),true)->XML_val("\\4",null,true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+), *([^),]+)\\)}/' => '<?= {dlr}item->XML_val("\\1",array("\\2","\\3"),true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+)\\)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)}/' => '<?= {dlr}item->obj("\\1",array("\\2"),true)->obj("\\3",null,true)->XML_val("\\4",null,true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+)\\)\\.([A-Za-z0-9_]+)}/' => '<?= {dlr}item->obj("\\1",array("\\2"),true)->XML_val("\\3",null,true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+)\\)}/' => '<?= {dlr}item->XML_val("\\1",array("\\2"),true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)}/' => '<?= {dlr}item->obj("\\1",null,true)->obj("\\2",null,true)->XML_val("\\3",null,true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)\\.([A-Za-z0-9_]+)}/' => '<?= {dlr}item->obj("\\1",null,true)->XML_val("\\2",null,true) ?>',
			'/{\\$([A-Za-z_][A-Za-z0-9_]*)}/' => '<?= {dlr}item->XML_val("\\1",null,true) ?>\\2',

			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\.([A-Za-z0-9_]+)\\(([^),]+)\\)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1")->XML_val("\\2",array("\\3"),true) ?>\\4',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\.([A-Za-z0-9_]+)\\(([^),]+), *([^),]+)\\)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1")->XML_val("\\2",array("\\3", "\\4"),true) ?>\\5',

			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+), *([^),]+)\\)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1",array("\\2","\\3"),true)->obj("\\4",null,true)->XML_val("\\5",null,true) ?>\\6',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+), *([^),]+)\\)\\.([A-Za-z0-9_]+)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1",array("\\2","\\3"),true)->XML_val("\\4",null,true) ?>\\5',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+), *([^),]+)\\)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->XML_val("\\1",array("\\2","\\3"),true) ?>\\4',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+)\\)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1",array("\\2"),true)->obj("\\3",null,true)->XML_val("\\4",null,true) ?>\\5',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+)\\)\\.([A-Za-z0-9_]+)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1",array("\\2"),true)->XML_val("\\3",null,true) ?>\\4',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\(([^),]+)\\)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->XML_val("\\1",array("\\2"),true) ?>\\3',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1",null,true)->obj("\\2",null,true)->XML_val("\\3",null,true) ?>\\4',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)\\.([A-Za-z0-9_]+)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->obj("\\1",null,true)->XML_val("\\2",null,true) ?>\\3',
			'/\\$([A-Za-z_][A-Za-z0-9_]*)([^A-Za-z0-9]|$)/' => '<?= {dlr}item->XML_val("\\1",null,true) ?>\\2',
		);
		
		$content = preg_replace(array_keys($replacements), array_values($replacements), $content);
		$content = str_replace('{dlr}','$',$content);

		// legacy
		$content = ereg_replace('<!-- +pc +([A-Za-z0-9_(),]+) +-->', '<' . '% control \\1 %' . '>', $content);
		$content = ereg_replace('<!-- +pc_end +-->', '<' . '% end_control %' . '>', $content);
		
		// < % cacheblock key, key.. % >
		$content = SSViewer_PartialParser::parse($template, $content);

		// < % control Foo % >
		$content = ereg_replace('<' . '% +control +([A-Za-z0-9_]+) +%' . '>', '<? array_push($itemStack, $item); if($loop = $item->obj("\\1")) foreach($loop as $key => $item) { ?>', $content);
		// < % control Foo.Bar % >
		$content = ereg_replace('<' . '% +control +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+) +%' . '>', '<? array_push($itemStack, $item); if(($loop = $item->obj("\\1")) && ($loop = $loop->obj("\\2"))) foreach($loop as $key => $item) { ?>', $content);
		// < % control Foo.Bar(Baz) % >
		$content = ereg_replace('<' . '% +control +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)\\(([^),]+)\\) +%' . '>', '<? array_push($itemStack, $item); if(($loop = $item->obj("\\1")) && ($loop = $loop->obj("\\2", array("\\3")))) foreach($loop as $key => $item) { ?>', $content);
		// < % control Foo(Bar) % >
		$content = ereg_replace('<' . '% +control +([A-Za-z0-9_]+)\\(([^),]+)\\) +%' . '>', '<? array_push($itemStack, $item); if($loop = $item->obj("\\1", array("\\2"))) foreach($loop as $key => $item) { ?>', $content);
		// < % control Foo(Bar, Baz) % >
		$content = ereg_replace('<' . '% +control +([A-Za-z0-9_]+)\\(([^),]+), *([^),]+)\\) +%' . '>', '<? array_push($itemStack, $item); if($loop = $item->obj("\\1", array("\\2","\\3"))) foreach($loop as $key => $item) { ?>', $content);
		// < % control Foo(Bar, Baz, Buz) % >
		$content = ereg_replace('<' . '% +control +([A-Za-z0-9_]+)\\(([^),]+), *([^),]+), *([^),]+)\\) +%' . '>', '<? array_push($itemStack, $item); if($loop = $item->obj("\\1", array("\\2", "\\3", "\\4"))) foreach($loop as $key => $item) { ?>', $content);
		$content = ereg_replace('<' . '% +end_control +%' . '>', '<? } $item = array_pop($itemStack); ?>', $content);
		$content = ereg_replace('<' . '% +debug +%' . '>', '<? Debug::show($item) ?>', $content);
		$content = ereg_replace('<' . '% +debug +([A-Za-z0-9_]+) +%' . '>', '<? Debug::show($item->cachedCall("\\1")) ?>', $content);

		// < % if val1.property % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+) +%' . '>', '<? if($item->obj("\\1",null,true)->hasValue("\\2")) {  ?>', $content);
		
		// < % if val1(parameter) % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+)\\(([A-Za-z0-9_-]+)\\) +%' . '>', '<? if($item->hasValue("\\1",array("\\2"))) {  ?>', $content);

		// < % if val1 % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+) +%' . '>', '<? if($item->hasValue("\\1")) {  ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+) +%' . '>', '<? } else if($item->hasValue("\\1")) {  ?>', $content);

		// < % if val1 || val2 % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+) *\\|\\|? *([A-Za-z0-9_]+) +%' . '>', '<? if($item->hasValue("\\1") || $item->hasValue("\\2")) { ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+) *\\|\\|? *([A-Za-z0-9_]+) +%' . '>', '<? else_if($item->hasValue("\\1") || $item->hasValue("\\2")) { ?>', $content);

		// < % if val1 && val2 % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+) *&&? *([A-Za-z0-9_]+) +%' . '>', '<? if($item->hasValue("\\1") && $item->hasValue("\\2")) { ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+) *&&? *([A-Za-z0-9_]+) +%' . '>', '<? else_if($item->hasValue("\\1") && $item->hasValue("\\2")) { ?>', $content);

		// < % if val1 == val2 % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+) *==? *"?([A-Za-z0-9_-]+)"? +%' . '>', '<? if($item->XML_val("\\1",null,true) == "\\2") {  ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+) *==? *"?([A-Za-z0-9_-]+)"? +%' . '>', '<? } else if($item->XML_val("\\1",null,true) == "\\2") {  ?>', $content);
		
		// < % if val1 != val2 % >
		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+) *!= *"?([A-Za-z0-9_-]+)"? +%' . '>', '<? if($item->XML_val("\\1",null,true) != "\\2") {  ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+) *!= *"?([A-Za-z0-9_-]+)"? +%' . '>', '<? } else if($item->XML_val("\\1",null,true) != "\\2") {  ?>', $content);

		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+) +%' . '>', '<? } else if(($test = $item->cachedCall("\\1")) && ((!is_object($test) && $test) || ($test && $test->exists()) )) {  ?>', $content);

		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+) +%' . '>', '<? $test = $item->obj("\\1",null,true)->cachedCall("\\2"); if((!is_object($test) && $test) || ($test && $test->exists())) {  ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+) +%' . '>', '<? } else if(($test = $item->obj("\\1",null,true)->cachedCall("\\2")) && ((!is_object($test) && $test) || ($test && $test->exists()) )) {  ?>', $content);

		$content = ereg_replace('<' . '% +if +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+) +%' . '>', '<? $test = $item->obj("\\1",null,true)->obj("\\2",null,true)->cachedCall("\\3"); if((!is_object($test) && $test) || ($test && $test->exists())) {  ?>', $content);
		$content = ereg_replace('<' . '% +else_if +([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+)\\.([A-Za-z0-9_]+) +%' . '>', '<? } else if(($test = $item->obj("\\1",null,true)->obj("\\2",null,true)->cachedCall("\\3")) && ((!is_object($test) && $test) || ($test && $test->exists()) )) {  ?>', $content);
		
		$content = ereg_replace('<' . '% +else +%' . '>', '<? } else { ?>', $content);
		$content = ereg_replace('<' . '% +end_if +%' . '>', '<? }  ?>', $content);

		// i18n - get filename of currently parsed template
		// CAUTION: No spaces allowed between arguments for all i18n calls!
		ereg('.*[\/](.*)',$template,$path);
		
		// i18n _t(...) - with entity only (no dots in namespace), 
		// meaning the current template filename will be added as a namespace. 
		// This applies only to "root" templates, not includes which should always have their namespace set already.
		// See getTemplateContent() for more information.
		$content = ereg_replace('<' . '% +_t\((\'([^\.\']*)\'|"([^\."]*)")(([^)]|\)[^ ]|\) +[^% ])*)\) +%' . '>', '<?= _t(\''. $path[1] . '.\\2\\3\'\\4) ?>', $content);
		// i18n _t(...)
		$content = ereg_replace('<' . '% +_t\((\'([^\']*)\'|"([^"]*)")(([^)]|\)[^ ]|\) +[^% ])*)\) +%' . '>', '<?= _t(\'\\2\\3\'\\4) ?>', $content);

		// i18n sprintf(_t(...),$argument) with entity only (no dots in namespace), meaning the current template filename will be added as a namespace
		$content = ereg_replace('<' . '% +sprintf\(_t\((\'([^\.\']*)\'|"([^\."]*)")(([^)]|\)[^ ]|\) +[^% ])*)\),\<\?= +([^\?]*) +\?\>) +%' . '>', '<?= sprintf(_t(\''. $path[1] . '.\\2\\3\'\\4),\\6) ?>', $content);
		// i18n sprintf(_t(...),$argument)
		$content = ereg_replace('<' . '% +sprintf\(_t\((\'([^\']*)\'|"([^"]*)")(([^)]|\)[^ ]|\) +[^% ])*)\),\<\?= +([^\?]*) +\?\>) +%' . '>', '<?= sprintf(_t(\'\\2\\3\'\\4),\\6) ?>', $content);

		// </base> isnt valid html? !? 
		$content = ereg_replace('<' . '% +base_tag +%' . '>', '<?= SSViewer::get_base_tag($val); ?>', $content);

		$content = ereg_replace('<' . '% +current_page +%' . '>', '<?= $_SERVER[SCRIPT_URL] ?>', $content);
		
		// add all requirements to the $requirements array
		preg_match_all('/<% require ([a-zA-Z]+)\(([^\)]+)\) %>/', $content, $requirements);
		$content = preg_replace('/<% require .* %>/', null, $content);
		
		// legacy
		$content = ereg_replace('<!-- +if +([A-Za-z0-9_]+) +-->', '<? if($item->cachedCall("\\1")) { ?>', $content);
		$content = ereg_replace('<!-- +else +-->', '<? } else { ?>', $content);
		$content = ereg_replace('<!-- +if_end +-->', '<? }  ?>', $content);
			
		// Fix link stuff
		$content = ereg_replace('href *= *"#', 'href="<?= SSViewer::$options[\'rewriteHashlinks\'] ? Convert::raw2att( $_SERVER[\'REQUEST_URI\'] ) : "" ?>#', $content);
	
		// Protect xml header
		$content = ereg_replace('<\?xml([^>]+)\?' . '>', '<##xml\\1##>', $content);

		// Turn PHP file into string definition
		$content = str_replace('<?=',"\nSSVIEWER;\n\$val .= ", $content);
		$content = str_replace('<?',"\nSSVIEWER;\n", $content);
		$content = str_replace('?>',";\n \$val .= <<<SSVIEWER\n", $content);
		
		$output  = "<?php\n";
		for($i = 0; $i < count($requirements[0]); $i++) {
			$output .= 'Requirements::' . $requirements[1][$i] . '(\'' . $requirements[2][$i] . "');\n";
		}
		$output .= '$val .= <<<SSVIEWER' . "\n" . $content . "\nSSVIEWER;\n"; 
		
		// Protect xml header @sam why is this run twice ?
		$output = ereg_replace('<##xml([^>]+)##>', '<' . '?xml\\1?' . '>', $output);
	
		return $output;
	}

	/**
	 * Returns the filenames of the template that will be rendered.  It is a map that may contain
	 * 'Content' & 'Layout', and will have to contain 'main'
	 */
	public function templates() {
		return $this->chosenTemplates;
	}
	
	/**
	 * @param string $type "Layout" or "main"
	 * @param string $file Full system path to the template file
	 */
	public function setTemplateFile($type, $file) {
		$this->chosenTemplates[$type] = $file;
	}
	
	/**
	 * Return an appropriate base tag for the given template.
	 * It will be closed on an XHTML document, and unclosed on an HTML document.
	 * 
	 * @param $contentGeneratedSoFar The content of the template generated so far; it should contain
	 * the DOCTYPE declaration.
	 */
	static function get_base_tag($contentGeneratedSoFar) {
		$base = Director::absoluteBaseURL();
		
		// Is the document XHTML?
		if(preg_match('/<!DOCTYPE[^>]+xhtml/i', $contentGeneratedSoFar)) {
			return "<base href=\"$base\"></base>";
		} else {
			return "<base href=\"$base\"><!--[if lte IE 6]></base><![endif]-->";
		}
	}
}

/**
 * Special SSViewer that will process a template passed as a string, rather than a filename.
 * @package sapphire
 * @subpackage view
 */
class SSViewer_FromString extends SSViewer {
	protected $content;
	
	public function __construct($content) {
		$this->content = $content;
	}
	
	public function process($item, $cache = null) {
		$template = SSViewer::parseTemplateContent($this->content, "string sha1=".sha1($this->content));

		$tmpFile = tempnam(TEMP_FOLDER,"");
		$fh = fopen($tmpFile, 'w');
		fwrite($fh, $template);
		fclose($fh);

		if(isset($_GET['showtemplate']) && $_GET['showtemplate']) {
			$lines = file($tmpFile);
			echo "<h2>Template: $tmpFile</h2>";
			echo "<pre>";
			foreach($lines as $num => $line) {
				echo str_pad($num+1,5) . htmlentities($line);
			}
			echo "</pre>";
		}

		$itemStack = array();
		$val = "";
		$valStack = array();
		
		$cache = SS_Cache::factory('cacheblock');
		
		include($tmpFile);
		unlink($tmpFile);
		

		return $val;
	}
}

/**
 * Handle the parsing for cacheblock tags.
 * 
 * Needs to be handled differently from the other tags, because cacheblock can take any number of arguments
 * 
 * This shouldn't be used as an example of how to add functionality to SSViewer - the eventual plan is to re-write
 * SSViewer using a proper parser (probably http://github.com/hafriedlander/php-peg), so that extra functionality
 * can be added without relying on ad-hoc parsers like this.
 */
class SSViewer_PartialParser {
 	
	static $opening_tag = '/< % [ \t]+ cacheblock [ \t]+ ([^%]+ [ \t]+)? % >/xS';
	
	static $argument_splitter = '/^\s* 
		( (\w+) \s* ( \( ([^\)]*) \) )? ) |  # A property lookup or a function call
		( \' [^\']+ \' ) |                   # A string surrounded by \'
		( " [^"]+ " )                        # A string surrounded by "
	\s*/xS';
	
	static $closing_tag = '/< % [ \t]+ end_cacheblock [ \t]+ % >/xS';
	
	static function parse($template, $content) {
		$parser = new SSViewer_PartialParser($template);
		
		$content = $parser->replaceOpeningTags($content);
		$content = $parser->replaceClosingTags($content);
		return $content;
	}
	
	function __construct($template) {
		$this->template = $template;
		$this->cacheblocks = 0;
	}
	
	function replaceOpeningTags($content) {
		return preg_replace_callback(self::$opening_tag, array($this, 'replaceOpeningTagsCallback'), $content);
	}
	
	function replaceOpeningTagsCallback($matches) {
		$this->cacheblocks += 1;
		$key = $this->key($matches);
		
		return '<? if ($partial = $cache->load('.$key.')) { $val .= $partial; } else { $valStack[] = $val; $val = ""; ?>';
	}
	
	function key($matches) {
		$parts = array();
		$parts[] = "'".preg_replace('/[^\w+]/', '_', $this->template)."'";

		// If there weren't any arguments, that'll do
		if (!@$matches[1]) return $parts[0];
		
		$current = 'preg_replace(\'/[^\w+]/\', \'_\', $item->';
		$keyspec = $matches[1];
		
		while (strlen($keyspec) && preg_match(self::$argument_splitter, $keyspec, $submatch)) {
			$joiner = substr($keyspec, strlen($submatch[0]), 1);
			$keyspec = substr($keyspec, strlen($submatch[0]) + 1);
			
			// If it's a property lookup or a function call
			if ($submatch[1]) {
				// Get the property
				$what = $submatch[2];
				$args = array();
				
				// Extract any arguments passed to the function call
				if (@$submatch[3]) {
					foreach (explode(',', $submatch[4]) as $arg) {
						$args[] = is_numeric($arg) ? (string)$arg : '"'.$arg.'"';
					}
				}
				
				$args = empty($args) ? 'null' : 'array('.implode(',',$args).')';
			
				// If this fragment ended with '.', then there's another lookup coming, so return an obj for that lookup
				if ($joiner == '.') {
					$current .= "obj(\"$what\", $args, true)->";
				}
				// Otherwise this is the end of the lookup chain, so add the resultant value to the key array and reset the key-get php fragement
				else {
					$parts[] = $current . "XML_val(\"$what\", $args, true))"; $current = 'preg_replace(\'/[^\w+]/\', \'_\', $item->';
				}
			}
			
			// Else it's a quoted string of some kind
			else if ($submatch[5] || $submatch[6]) {
				$parts[] = $submatch[5] ? $submatch[5] : $submatch[6];
			}
			
		}
		
		return implode(".'_'.", $parts);
	}	
	
	function replaceClosingTags($content) {
		return preg_replace(self::$closing_tag, '<? $cache->save($val); $val = array_pop($valStack) . $val; } ?>', $content);
	}
}

function supressOutput() {
	return "";
}

?>
