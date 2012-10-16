<?php
/**
 * A workflow action that notifies users attached to the workflow path that they have a task awaiting them.
 *
 * @license    BSD License (http://silverstripe.org/bsd-license/)
 * @package    advancedworkflow
 * @subpackage actions
 */
class NotifyUsersWorkflowAction extends WorkflowAction {

	public static $db = array(
		'EmailSubject'			=> 'Varchar(100)',
		'EmailFrom'				=> 'Varchar(50)',
		'EmailTemplate'			=> 'Text',
		'ListingTemplateID'		=> 'Int',
	);

	public static $icon = 'advancedworkflow/images/notify.png';

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->addFieldsToTab('Root.Main', array(
			new HeaderField('NotificationEmail', $this->fieldLabel('NotificationEmail')),
			new LiteralField('NotificationNote', '<p>' . $this->fieldLabel('NotificationNote') . '</p>'),
			new TextField('EmailSubject', $this->fieldLabel('EmailSubject')),
			new TextField('EmailFrom', $this->fieldLabel('EmailFrom')),

			new TextareaField('EmailTemplate', $this->fieldLabel('EmailTemplate')),
			new ToggleCompositeField('FormattingHelpContainer',
				$this->fieldLabel('FormattingHelp'), new LiteralField('FormattingHelp', $this->getFormattingHelp()))
		));

		if (class_exists('ListingPage')) {
			// allow the user to select an existing 'listing template'. The "getItems()" for that template
			// will be the list of items in the workflow
			$templates = DataObject::get('ListingTemplate');
			$opts = array();
			if ($templates) {
				$opts = $templates->map();
			}

			$fields->addFieldToTab('Root.Main', new DropdownField('ListingTemplateID', $this->fieldLabel('ListingTemplateID'), $opts, '', null, '(choose)'), 'EmailTemplate');
		}

		if ($this->ListingTemplateID) {
			$fields->removeFieldFromTab('Root.Main', 'EmailTemplate');
		}

		return $fields;
	}

	public function fieldLabels($relations = true) {
		return array_merge(parent::fieldLabels($relations), array(
			'NotificationEmail' => _t('NotifyUsersWorkflowAction.NOTIFICATIONEMAIL', 'Notification Email'),
			'NotificationNote'  => _t('NotifyUsersWorkflowAction.NOTIFICATIONNOTE',
				'All users attached to the workflow will be sent an email when this action is run.'),
			'EmailSubject'      => _t('NotifyUsersWorkflowAction.EMAILSUBJECT', 'Email subject'),
			'EmailFrom'         => _t('NotifyUsersWorkflowAction.EMAILFROM', 'Email from'),
			'ListingTemplateID' => _t('NotifyUsersWorkflowAction.LISTING_TEMPLATE', 
				'Listing Template - Items will be the list of all actions in the workflow (synonym to Actions). 
					Also available will be all properties of the current Workflow Instance'),
			'EmailTemplate'     => _t('NotifyUsersWorkflowAction.EMAILTEMPLATE', 'Email template'),
			'FormattingHelp'    => _t('NotifyUsersWorkflowAction.FORMATTINGHELP', 'Formatting Help')
		));
	}

	public function execute(WorkflowInstance $workflow) {
		$email   = new Email;
		$members = $workflow->getAssignedMembers();
		$emails  = '';

		if(!$members || !count($members)) return;

		foreach($members as $member) {
			if($member->Email) $emails .= "$member->Email, ";
		}
		
		$context   = $this->getContextFields($workflow->getTarget());
		$member    = $this->getMemberFields();
		$variables = array();
		
		foreach($context as $field => $val) $variables["\$Context.$field"] = $val;
		foreach($member as $field => $val)  $variables["\$Member.$field"] = $val;

		$pastActions = $workflow->Actions()->sort('Created DESC');
		$variables["\$CommentHistory"] = $this->customise(array(
			'PastActions'=>$pastActions,
			'Now'=>SS_Datetime::now()
		))->renderWith('CommentHistory');

		$subject = str_replace(array_keys($variables), array_values($variables), $this->EmailSubject);
		
		if ($this->ListingTemplateID) {
			$item = $workflow->customise(array(
				'Items'		=> $workflow->Actions(),
				'Member'	=> Member::currentUser(),
				'Context'	=> $workflow->getTarget(),
			));

			$template = DataObject::get_by_id('ListingTemplate', $this->ListingTemplateID);
			$view = SSViewer::fromString($template->ItemTemplate);
			$body = $view->process($item);
		} else {
			$body    = str_replace(array_keys($variables), array_values($variables), $this->EmailTemplate);
		}

		$email->setSubject($subject);
		$email->setFrom($this->EmailFrom);
		$email->setTo(substr($emails, 0, -2));
		$email->setBody($body);
		$email->send();

		return true;
	}

	/**
	 * @param  DataObject $target
	 * @return array
	 */
	public function getContextFields(DataObject $target) {
		$fields = $target->inheritedDatabaseFields();
		$result = array();

		foreach($fields as $field=>$fieldType) {
			$result[$field] = $target->$field;
		}

		if($target instanceof CMSPreviewable) {
			$result['CMSLink'] = $target->CMSEditLink();
		} else if ($target->hasMethod('WorkflowLink')) {
			$result['CMSLink'] = $target->WorkflowLink();
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public function getMemberFields() {
		$member = Member::currentUser();
		$result = array();

		if($member) foreach($member->summaryFields() as $field => $title) {
			$result[$field] = $member->$field;
		}

		if($member && !array_key_exists('Name', $result)) {
			$result['Name'] = $member->getName();
		}

		return $result;
	}
	

	/**
	 * Returns a basic set of instructions on how email templates are populated with variables.
	 *
	 * @return string
	 */
	public function getFormattingHelp() {
		$note = _t('NotifyUsersWorkflowAction.FORMATTINGNOTE',
			'Notification emails can contain HTML formatting. The following special variables are replaced with their
			respective values in both the email subject and template/body.');
		$member = _t('NotifyUsersWorkflowAction.MEMBERNOTE',
			'These fields will be populated from the member that initiates the notification action.');
		$context = _t('NotifyUsersWorkflowAction.CONTEXTNOTE',
			'Any database fields from the workflow target will be available. Additionally, the CMSLink variable will
			contain a link to edit the workflow target in the CMS (if it is a SiteTree object).');
		$fieldName = _t('NotifyUsersWorkflowAction.FIELDNAME', 'Field name');
		$commentHistory = _t('NotifyUsersWorkflowAction.COMMENTHISTORY', 'Comment history up to this notification.');

		$memberFields = implode(', ', array_keys($this->getMemberFields()));

		return "<p>$note</p>
			<p><strong>\$Member.($memberFields)</strong><br>$member</p>
			<p><strong>\$Context.($fieldName)</strong><br>$context</p>
			<p><strong>\$CommentHistory</strong><br>$commentHistory</p>";
	}

}
