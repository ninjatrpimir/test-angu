import { NgModule }       from '@angular/core';
import { HttpClientModule } from '@angular/common/http';
import { BrowserModule }  from '@angular/platform-browser';
import { FormsModule }    from '@angular/forms';
import { BrowserAnimationsModule } from '@angular/platform-browser/animations';

import { Router } from '@angular/router';

import { AppComponent }            from './app.component';
import { PageNotFoundComponent }   from './page-not-found/page-not-found.component';
import { ComposeMessageComponent } from './compose-message/compose-message.component';

import { AppRoutingModule }        from './app-routing.module';
import { HeroesModule }            from './heroes/heroes.module';
import { AuthModule }              from './auth/auth.module';
import { DashboardModule }         from './dashboard/dashboard.module';

import { DashboardComponent }      from './dashboard/dashboard/dashboard.component';

// bootstrap, font awesome
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { AngularFontAwesomeModule } from 'angular-font-awesome';

// dialogbox
import { DialogComponent } from './dialog/dialog/dialog.component';
import { DialogTemplateComponent } from './dialog/dialog-template/dialog-template.component';
import { DialogService } from './dialog/dialog/dialog.service';
// import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatDialogModule } from '@angular/material/dialog';

// provjeriti je li potrebno
import { MatButtonModule, MatInputModule, MatIconModule } from '@angular/material';

@NgModule({
  imports: 
          [
            BrowserModule,
            BrowserAnimationsModule,
            HttpClientModule,
            FormsModule,
            HeroesModule,
            AuthModule,
            AppRoutingModule,
            DashboardModule,
            NgbModule,
            AngularFontAwesomeModule,
            MatDialogModule,
            // MatDialogRef,
          ],
  declarations: 
          [
            AppComponent,
            ComposeMessageComponent,
            PageNotFoundComponent,
            // DashboardComponent,
            DialogComponent,
            DialogTemplateComponent
          ],       
  providers: 
          [ 
            DialogService, 
            // {provide:MatDialogRef, useValue: {}},
          ],
  bootstrap: 
          [ AppComponent ],
  entryComponents: 
          [ 
            DialogTemplateComponent 
          ],
})
export class AppModule {
  // Diagnostic only: inspect router configuration
  constructor(router: Router) {
    // Use a custom replacer to display function names in the route configs
    // const replacer = (key, value) => (typeof value === 'function') ? value.name : value;

    // console.log('Routes: ', JSON.stringify(router.config, replacer, 2));
  }
}


/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/