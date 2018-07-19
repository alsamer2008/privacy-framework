<?php
/**
 * @package    Joomla.Cli
 *
 * @copyright  Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Scheduler CLI.
 *
 * This is a command-line script that trigger job plugin event onExecuteScheduledTask
 *
 */

// We are a valid entry point.
const _JEXEC = 1;

// Load system defines
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	require_once dirname(__DIR__) . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', dirname(__DIR__));
	require_once JPATH_BASE . '/includes/defines.php';
}

// Get the framework.
require_once JPATH_LIBRARIES . '/import.legacy.php';

// Bootstrap the CMS libraries.
require_once JPATH_LIBRARIES . '/cms.php';

// Import the configuration.
require_once JPATH_CONFIGURATION . '/configuration.php';

// System configuration.
$config = new JConfig;
define('JDEBUG', $config->debug);

// Configure error reporting to maximum for CLI output.
error_reporting(E_ALL);
ini_set('display_errors', 1);
use Joomla\Registry\Registry;

// Load Library language
$lang = JFactory::getLanguage();

// Try the finder_cli file in the current language (without allowing the loading of the file in the default language)
$lang->load('scheduler_cli', JPATH_SITE, null, false, false)
// Fallback to the finder_cli file in the default language
|| $lang->load('scheduler_cli', JPATH_SITE, null, true);

/**
 * A command line cron job to run the job plugins event.
 *
 * @since  __DEPLOY_VERSION__
 */
class SchedulerCli extends JApplicationCli
{
	/**
	 * Start time for the process
	 *
	 * @var    string
	 * @since  __DEPLOY_VERSION__
	 */
	private $time = null;

	/**
	 * Entry point for the Scheduler CLI script
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function doExecute()
	{
		// Print a blank line.
		$this->out(JText::_('SCHEDULER_CLI'));
		$this->out('============================');

		// Initialize the time value.
		$this->time = microtime(true);

		// Remove the script time limit.
		@set_time_limit(0);

		// Fool the system into thinking we are running as JSite.
		$_SERVER['HTTP_HOST'] = 'domain.com';
		JFactory::getApplication('site');

		$this->checkAndTrigger();

		// Total reporting.
		$this->out(JText::sprintf('SCHEDULER_CLI_PROCESS_COMPLETE', round(microtime(true) - $this->time, 3)), true);
		$this->out(JText::sprintf('SCHEDULER_CLI_PEAK_MEMORY_USAGE', number_format(memory_get_peak_usage(true))));

		// Print a blank line at the end.
		$this->out();
	}

	/**
	 * Check and Trigger job
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function checkAndTrigger()
	{
		// Looks for job plugins
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName(array('extension_id', 'name', 'params')))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('job'));
		try
		{
			$tasks = $db->setQuery($query)->loadObjectList();
		}
		catch (JDatabaseException $e)
		{
			return;
		}

		if (empty($tasks))
		{
			return;
		}

		// Unleash hell
		JPluginHelper::importPlugin('job');
		$dispatcher = JEventDispatcher::getInstance();

		foreach ($tasks as $task)
		{
			$taskParams = json_decode($task->params, true);
			$now        = time();

			// Sanity check
			if ((!isset($taskParams['lastrun'])) || (!isset($taskParams['cachetimeout'])) || (!isset($taskParams['unit'])))
			{
				continue;
			}

			$last          = (int) $taskParams['lastrun'];
			$cache_timeout = (int) $taskParams['cachetimeout'];
			$cache_timeout = (int) $taskParams['unit'] * $cache_timeout;

			if ((abs($now - $last) < $cache_timeout))
			{
				continue;
			}

			$startTime = microtime(true);
			JLog::add(
				'CLI Scheduler:' . $task->extension_id . ':' . $task->name,
				JLog::INFO,
				'scheduler'
			);
			$this->out('Scheduling Job:' . $task->extension_id . ':' . $task->name);

			// Update lastrun
			$this->updateLastRun($task->extension_id, $task->params);
			$dispatcher->trigger('onExecuteScheduledTask', array($task));
			$endTime    = microtime(true);
			$timeToLoad = sprintf('%0.2f', $endTime - $startTime);
			JLog::add(
				'CLI Scheduler:' . $task->extension_id . ':' . $task->name . ' took ' . $timeToLoad . ' seconds',
				JLog::INFO,
				'scheduler'
			);
			$this->out('Runned Job:' . $task->extension_id . ':' . $task->name . ' took ' . $timeToLoad . ' seconds');
		}
	}

	/**
	 * Clears cache groups. We use it to clear the plugins cache after we update the last run timestamp.
	 *
	 * @param   array  $clearGroups   The cache groups to clean
	 * @param   array  $cacheClients  The cache clients (site, admin) to clean
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function clearCacheGroups(array $clearGroups, array $cacheClients = array(0, 1))
	{
		$conf = JFactory::getConfig();

		foreach ($clearGroups as $group)
		{
			foreach ($cacheClients as $client_id)
			{
				try
				{
					$options = array(
						'defaultgroup' => $group,
						'cachebase'    => $client_id ? JPATH_ADMINISTRATOR . '/cache' :
							$conf->get('cache_path', JPATH_SITE . '/cache')
					);

					$cache = JCache::getInstance('callback', $options);
					$cache->clean();
				}
				catch (Exception $e)
				{
					// Ignore it
				}
			}
		}
	}

	/**
	 * Update last run.
	 *
	 * @param   integer   $eid     The plugin id
	 * @param   registry  $params  The plugin parameters
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function updateLastRun($eid, $params)
	{
		// Update last run status
		$registry = new Registry($params);
		$registry->set('lastrun', time());

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('params') . ' = ' . $db->quote($registry->toString('JSON')))
			->where($db->quoteName('extension_id') . ' = ' . $eid);

		try
		{
			// Lock the tables to prevent multiple plugin executions causing a race condition
			$db->lockTable('#__extensions');
		}
		catch (JDatabaseException $e)
		{
			// If we can't lock the tables it's too risky to continue execution
			return;
		}

		try
		{
			// Update the plugin parameters
			$result = $db->setQuery($query)->execute();

			$this->clearCacheGroups(array('com_plugins'), array(0, 1));
		}
		catch (JDatabaseException $exc)
		{
			// If we failed to execute
			$db->unlockTables();
			$result = false;
		}

		try
		{
			// Unlock the tables after writing
			$db->unlockTables();
		}
		catch (JDatabaseException $e)
		{
			// If we can't unlock the tables assume we have somehow failed
			$result = false;
		}

		// Abort on failure
		if (!$result)
		{
			return;
		}
	}	
}

// Instantiate the application object, passing the class name to JCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('SchedulerCli')->execute();
