// Angular Imports
import { Injectable } from '@angular/core';

// This Service's Components
import { MatDialog, MatDialogConfig } from '@angular/material';
import { DialogTemplateComponent } from '../dialog-template/dialog-template.component';

@Injectable()

export class DialogService 
{
    constructor(public dialog: MatDialog){}

    openModal(title:string, message:string, yes:Function = null, no:Function = null) 
    {
        const dialogConfig = new MatDialogConfig();

        // dialogConfig.disableClose = true;
        dialogConfig.minWidth = 400;
        dialogConfig.autoFocus = true;
        dialogConfig.data = {
            title: title,
            message: message
        };

        const dialogRef = this.dialog.open(DialogTemplateComponent, dialogConfig)

        dialogRef.afterClosed().subscribe(result => {
            if(result)
            {
                if (yes)
                {
                    yes();
                }
            } 
            else
            {
                if(no) 
                {
                    no();
                }
            }
        });
    }
}