import { Component, Inject } from '@angular/core';
import { MAT_DIALOG_DATA } from '@angular/material';

@Component({
    moduleId: module.id,
    selector: 'dialog-template',
    templateUrl: 'dialog-template.component.html',
    styleUrls: ['dialog-template.component.scss']
})
export class DialogTemplateComponent 
{

    modalTitle: string;
    modalMessage: string;
    modalType: ModalType = ModalType.INFO;

    constructor(@Inject(MAT_DIALOG_DATA) public data: any)
    {
        this.modalTitle = data.title;
        this.modalMessage = data.message;
        this.modalType = data.type;

        console.log(data, 'data dialog template');
    }
}

export enum ModalType 
{
    INFO = 'info',
    WARN = 'warn'
}
