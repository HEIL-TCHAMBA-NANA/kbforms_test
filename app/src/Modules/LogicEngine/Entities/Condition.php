<?php
namespace Modules\LogicEngine\Entities;

class Condition {
    public int    $id;
    public int    $formId;
    public int    $sourceQuestionId;
    public string $operator;   // 'eq' | 'neq' | 'contains'
    public string $value;
    public int    $targetSectionId;

    public function __construct(array $row) {
        $this->id               = (int)$row['id'];
        $this->formId           = (int)$row['form_id'];
        $this->sourceQuestionId = (int)$row['source_question_id'];
        $this->operator         = $row['operator'];
        $this->value            = $row['value'];
        $this->targetSectionId  = (int)$row['target_section_id'];
    }

    public function toArray(): array {
        return [
            'id'                 => $this->id,
            'form_id'            => $this->formId,
            'source_question_id' => $this->sourceQuestionId,
            'operator'           => $this->operator,
            'value'              => $this->value,
            'target_section_id'  => $this->targetSectionId,
        ];
    }
}
