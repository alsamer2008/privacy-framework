<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Job.privacyconsent
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Utilities\ArrayHelper;

/**
 * An example custom privacyconsent plugin.
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgJobPrivacyconsent extends JPlugin
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 * @since  __DEPLOY_VERSION__
	 */
	protected $app;

	/**
	 * Database object.
	 *
	 * @var    JDatabaseDriver
	 * @since  __DEPLOY_VERSION__
	 */
	protected $db;



	/**
	 * The privacy consent expiration check code is triggered after the page has fully rendered.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExecuteScheduledTask()
	{
		// Delete the expired privacy consent
		$this->deleteExpiredConsents();

		// Reminder for privacy consent expires soon 
		$this->remindExpiringConsents();

	}

	/**
	 * Method to send the reminder for privacy consents renew
	 *
	 * @return  int
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function remindExpiringConsents()
	{
		// Load the parameters.
		$expire = (int) $this->params->get('consentexpiration', 365);
		$remind = (int) $this->params->get('remind', 30);
		$now    = JFactory::getDate()->toSql();
		$period = '-' . ($expire - $remind);

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(array('r.id', 'r.user_id', 'u.email')));
		$query->from($db->quoteName('#__privacy_consents', 'r'));
		$query->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = r.user_id');
		$query->where($query->dateAdd($now, $period, 'DAY') . ' > ' . $db->quoteName('created'));
		$query->where($db->quoteName('remind') . ' = 0');
		$db->setQuery($query);

		try
		{
			$users = $db->loadObjectList();
		}
		catch (JDatabaseException $exception)
		{
			return false;
		}

		if (!count($users) > 0)
		{
			return false;
		}

		foreach ($users as $user)
		{
			$token       = JApplicationHelper::getHash(JUserHelper::genRandomPassword());
			$hashedToken = JUserHelper::hashPassword($token);

			// The mail
			try
			{
				$app = JFactory::getApplication();

				$linkMode = $app->get('force_ssl', 0) == 2 ? 1 : -1;

				$substitutions = array(
					'[SITENAME]' => $app->get('sitename'),
					'[URL]'      => JUri::root(),
					'[TOKENURL]' => JRoute::link('site', 'index.php?option=com_privacy&view=remind&confirm_token=' . $token, false, $linkMode),
					'[FORMURL]'  => JRoute::link('site', 'index.php?option=com_privacy&view=remind', false, $linkMode),
					'[TOKEN]'    => $token,
					'\\n'        => "\n",
				);

				$emailSubject = JText::_('PLG_SYSTEM_PRIVACYCONSENT_EMAIL_REMIND_SUBJECT');
				$emailBody = JText::_('PLG_SYSTEM_PRIVACYCONSENT_EMAIL_REMIND_BODY');

				foreach ($substitutions as $k => $v)
				{
					$emailSubject = str_replace($k, $v, $emailSubject);
					$emailBody    = str_replace($k, $v, $emailBody);
				}

				$mailer = JFactory::getMailer();
				$mailer->setSubject($emailSubject);
				$mailer->setBody($emailBody);
				$mailer->addRecipient($user->email);

				$mailResult = $mailer->Send();

				if ($mailResult instanceof JException)
				{
					return false;
				}
				elseif ($mailResult === false)
				{
					return false;
				}

				// Update the privacy_consents item for not send the reminder again
				$query->clear()
					->update($db->quoteName('#__privacy_consents'))
					->set($db->quoteName('remind') . ' = 1 ')
					->set($db->quoteName('token') . ' = ' . $db->quote($hashedToken))
					->where($db->quoteName('id') . ' = ' . $db->quote($user->id));
				$db->setQuery($query);

				try
				{
					$db->execute();
				}
				catch (RuntimeException $e)
				{
					return false;
				}

				return true;
			}
			catch (phpmailerException $exception)
			{
				return false;
			}
		}
	}

	/**
	 * Method to delete the expired privacy consents
	 *
	 * @return  int
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function deleteExpiredConsents()
	{
		// Load the parameters.
		$expire = (int) $this->params->get('consentexpiration', 365);
		$now    = JFactory::getDate()->toSql();
		$period = '-' . $expire;

		$db    = $this->db;
		$query = $db->getQuery(true)
			->select($db->quoteName(array('id', 'user_id')))
			->from($db->quoteName('#__privacy_consents'));
		$query->where($query->dateAdd($now, $period, 'DAY') . ' > ' . $db->quoteName('created'));
		$db->setQuery($query);

		try
		{
			$users = $db->loadObjectList();
		}
		catch (RuntimeException $e)
		{
			return false;
		}

		// Push a notification to the site's super users
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_messages/models', 'MessagesModel');
		JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_messages/tables');
		/** @var MessagesModelMessage $messageModel */
		$messageModel = JModelLegacy::getInstance('Message', 'MessagesModel');

		foreach ($users as $user)
		{
			$query = $db->getQuery(true)
				->delete($db->quoteName('#__privacy_consents'));
			$query->where($db->quoteName('id') . ' = ' . $user->id);
			$db->setQuery($query);

			try
			{
				$db->execute();
			}
			catch (RuntimeException $e)
			{
				return false;
			}

			$messageModel->notifySuperUsers(
				JText::_('PLG_SYSTEM_PRIVACYCONSENT_NOTIFICATION_USER_PRIVACY_EXPIRED_SUBJECT'),
				JText::sprintf('PLG_SYSTEM_PRIVACYCONSENT_NOTIFICATION_USER_PRIVACY_EXPIRED_MESSAGE', $user->user_id)
			);
		}

		return true;
	}


}
