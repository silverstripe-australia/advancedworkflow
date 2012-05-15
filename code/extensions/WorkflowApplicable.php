<?php
/**
 * DataObjects that have the WorkflowApplicable extension can have a
 * workflow definition applied to them. At some point, the workflow definition is then
 * triggered.
 *
 * @author  marcus@silverstripe.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */
class WorkflowApplicable extends DataObjectDecorator {
	
	/**
	 * 
	 * A cache var for the current workflow instance
	 *
	 * @var WorkflowInstance
	 */
	protected $currentInstance;
	
	public function extraStatics() {
		return array(
			'has_one' => array(
				'WorkflowDefinition' => 'WorkflowDefinition',
			)
		);
	}

	public function updateCMSFields(FieldSet $fields) {
		$service = singleton('WorkflowService');

		if($effective = $service->getDefinitionFor($this->owner)) {
			$effectiveTitle = $effective->Title;
		} else {
			$effectiveTitle = _t('WorkflowApplicable.NONE', '(none)');
		}

		$allDefinitions = array(_t('WorkflowApplicable.INHERIT', 'Inherit from parent'));

		if($definitions = $service->getDefinitions()) {
			$allDefinitions += $definitions->map();
		}
		
		$tab = $fields->fieldByName('Root') ? 'Root.Workflow' : 'BottomRoot.Workflow';
		
		$applyWorkflowField = null;
		
		
		$fields->addFieldToTab($tab, new HeaderField('AppliedWorkflowHeader', _t('WorkflowApplicable.APPLIEDWORKFLOW', 'Applied Workflow')));

		if (Permission::check('APPLY_WORKFLOW')) {
			$fields->addFieldToTab($tab, new DropdownField('WorkflowDefinitionID',
				_t('WorkflowApplicable.DEFINITION', 'Applied Workflow'), $allDefinitions));
			
		}
		
		$fields->addFieldToTab($tab, new ReadonlyField('EffectiveWorkflow',
				_t('WorkflowApplicable.EFFECTIVE_WORKFLOW', 'Effective Workflow'), $effectiveTitle));
		$fields->addFieldToTab($tab, new HeaderField('WorkflowLogHeader', _t('WorkflowApplicable.WORKFLOWLOG', 'Workflow Log')));
		$fields->addFieldToTab($tab, $logTable = new ComplexTableField(
				$this->owner, 'WorkflowLog', 'WorkflowInstance', null, 'getActionsSummaryFields',
				sprintf('"TargetClass" = \'%s\' AND "TargetID" = %d', $this->owner->class, $this->owner->ID)
			));

		$logTable->setRelationAutoSetting(false);
		$logTable->setPermissions(array('show'));
		$logTable->setPopupSize(760, 420);
	}

	public function updateCMSActions($actions) {
		$svc = singleton('WorkflowService');
		$active = $svc->getWorkflowFor($this->owner);

		if ($active) {
			if ($this->canEditWorkflow()) {
				$actions->push(new FormAction('updateworkflow', _t('WorkflowApplicable.UPDATE_WORKFLOW', 'Update Workflow')));
			}
		} else {
			$effective = $svc->getDefinitionFor($this->owner);
			if ($effective) {
				// we can add an action for starting off the workflow at least
				$initial = $effective->getInitialAction();
				$actions->push(new FormAction('startworkflow', $initial->Title));
			}
		}
	}
	
	public function updateFrontendActions($actions){
		Debug::show('here');
		$svc = singleton('WorkflowService');
		$active = $svc->getWorkflowFor($this->owner);

		if ($active) {
			if ($this->canEditWorkflow()) {
				$actions->push(new FormAction('updateworkflow', _t('WorkflowApplicable.UPDATE_WORKFLOW', 'Update Workflow')));
			}
		} else {
			$effective = $svc->getDefinitionFor($this->owner);
			if ($effective) {
				// we can add an action for starting off the workflow at least
				$initial = $effective->getInitialAction();
				$actions->push(new FormAction('startworkflow', $initial->Title));
			}
		}
	}
	
	/**
	 * After a workflow item is written, we notify the
	 * workflow so that it can take action if needbe
	 */
	public function onAfterWrite() {
		$instance = $this->getWorkflowInstance();
		if ($instance && $instance->CurrentActionID) {
			$action = $instance->CurrentAction()->BaseAction()->targetUpdated($instance);
		}
	}

	/**
	 * Gets the current instance of workflow
	 *
	 * @return WorkflowInstance
	 */
	public function getWorkflowInstance() {
		if (!$this->currentInstance) {
			$svc = singleton('WorkflowService');
			$this->currentInstance = $svc->getWorkflowFor($this->owner);
		}

		return $this->currentInstance;
	}


	/**
	 * Gets the history of a workflow instance
	 *
	 * @return DataObjectSet
	 */
	public function getWorkflowHistory($limit = null) {
		$svc = singleton('WorkflowService');
		return $svc->getWorkflowHistoryFor($this->owner, $limit);
	}


	/**
	 * Check all recent WorkflowActionIntances and return the most recent one with a Comment
	 *
	 * @return WorkflowActionInstance
	 */
	public function RecentWorkflowComment($limit = 10){
		if($actions = $this->getWorkflowHistory($limit)){
			foreach ($actions as $action) {
				if ($action->Comment != '') {
					return $action;
				}
			}
		}
	}
	

	/**
	 * Content can never be directly publishable if there's a workflow applied.
	 *
	 * If there's an active instance, then it 'might' be publishable
	 */
	public function canPublish() {
		if ($active = $this->getWorkflowInstance()) {
			return $active->canPublishTarget($this->owner);
		}

		// otherwise, see if there's any workflows applied. If there are, then we shouldn't be able
		// to directly publish
		if ($effective = singleton('WorkflowService')->getDefinitionFor($this->owner)) {
			return false;
		}
		
	}

	/**
	 * Can only edit content that's NOT in another person's content changeset
	 */
	public function canEdit() {
		if ($active = $this->getWorkflowInstance()) {
			return $active->canEditTarget($this->owner);
		}
	}

	/**
	 * Can a user edit the current workflow attached to this item?
	 */
	public function canEditWorkflow() {
		$active = $this->getWorkflowInstance();
		if ($active) {
			return $active->canEdit();
		}
		return false;
	}
}