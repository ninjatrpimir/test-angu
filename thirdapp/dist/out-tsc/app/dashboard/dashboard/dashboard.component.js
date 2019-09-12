var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { switchMap } from 'rxjs/operators';
import { Component } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { DashboardService } from '../dashboard.service';
var DashboardComponent = /** @class */ (function () {
    function DashboardComponent(service, route) {
        this.service = service;
        this.route = route;
    }
    DashboardComponent.prototype.ngOnInit = function () {
        var _this = this;
        this.commissions$ = this.route.paramMap.pipe(switchMap(function (params) {
            // (+) before `params.get()` turns the string into a number
            _this.selectedId = +params.get('id');
            return _this.service.getCommissions();
        }));
    };
    DashboardComponent = __decorate([
        Component({
            moduleId: module.id,
            selector: 'dashboard',
            templateUrl: 'dashboard.component.html',
            styleUrls: ['dashboard.component.scss']
        }),
        __metadata("design:paramtypes", [DashboardService,
            ActivatedRoute])
    ], DashboardComponent);
    return DashboardComponent;
}());
export { DashboardComponent };
//# sourceMappingURL=dashboard.component.js.map