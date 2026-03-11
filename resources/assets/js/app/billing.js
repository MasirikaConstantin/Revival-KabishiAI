'use strict';

import Alpine from 'alpinejs';
import api from './api';
import { getPlanList } from './helpers';
import { toast } from '../base/toast';

export function billingView() {
    Alpine.data('billing', () => ({
        orders: [],

        init() {
            this.getOrders();
        },

        cancelSubscription() {
            api.delete(`/billing/subscription`)
                .then(() => {
                    toast.success('Subscription cancelled!');

                    window.modal.close();

                    // Reload the page to reflect the changes
                    window.location.reload();
                });
        },

        getOrders() {
            api.get(`/billing/orders`, {
                sort: 'created_at:desc',
                limit: 3
            })
                .then(response => response.json())
                .then(list => this.orders = list.data);
        }
    }));

    Alpine.data('freshpayReceipt', (orderId, initialStatus) => ({
        orderId: orderId,
        status: initialStatus,
        isChecking: false,
        timer: null,
        lastCheckedAt: null,

        init() {
            if (this.status !== 'pending') {
                return;
            }

            this.timer = window.setInterval(() => {
                this.checkStatus(false);
            }, 8000);
        },

        checkStatus(showLoader = true) {
            if (this.isChecking) {
                return;
            }

            this.isChecking = showLoader;

            api.get(`/billing/orders/${this.orderId}`)
                .then(response => response.json())
                .then(order => {
                    this.status = order.status;
                    this.lastCheckedAt = new Date().toLocaleTimeString();

                    if (order.status !== 'pending') {
                        window.clearInterval(this.timer);
                        window.location.reload();
                    }
                })
                .finally(() => {
                    this.isChecking = false;
                });
        }
    }));

    Alpine.data('plans', () => ({
        state: 'initial',
        plans: {
            monthly: [],
            yearly: [],
            lifetime: []
        },
        freePlan: null,
        cycle: null,
        showSwitch: false,

        init() {
            getPlanList().then(plans => {
                if (!plans) {
                    this.state = 'empty';
                    return;
                }

                let free = plans.filter(
                    plan =>
                        plan.price === 0 && ['monthly', 'yearly'].includes(plan.billing_cycle)
                );

                if (free.length == 1) {
                    this.freePlan = free[0];
                }

                ['monthly', 'yearly', 'lifetime'].forEach(cycle => {
                    this.plans[cycle] = plans.filter(
                        plan =>
                            plan.billing_cycle === cycle && (!this.freePlan || plan.id !== this.freePlan.id)
                    );
                });

                // Set cycle to the first available cycle
                if (this.plans.monthly.length > 0) {
                    this.cycle = 'monthly';
                } else if (this.plans.yearly.length > 0) {
                    this.cycle = 'yearly';
                } else if (this.plans.lifetime.length > 0) {
                    this.cycle = 'lifetime';
                }

                if (!this.$store.workspace.is_eligible_for_free_plan) {
                    this.freePlan = null;
                }

                this.state = this.freePlan || this.plans.monthly.length > 0 || this.plans.yearly.length > 0 || this.plans.lifetime.length > 0 ? 'normal' : 'empty';

                // Show the switcher if at least two cycles are available

                // Set showSwitch to true if at least two plans have a non-zero length
                this.showSwitch = Object.values(this.plans).map(plan => plan.length).filter(length => length > 0).length >= 2;
            });
        }
    }));

    Alpine.data('packs', () => ({
        state: 'initial',
        packs: [],

        init() {
            getPlanList().then(plans => {
                if (!plans) {
                    this.state = 'empty';
                    return;
                }

                this.packs = plans.filter(plan => plan.billing_cycle === 'one-time');
                this.state = this.packs.length > 0 ? 'normal' : 'empty';
            });
        }
    }));

    Alpine.data('customCredits', (options) => ({
        isProcessing: false,
        currency: null,
        amount: null,
        value: null,
        min: null,
        max: null,
        rate: 0,
        packs: [],
        invalidValue: false,

        init() {
            this.currency = JSON.parse(options.currency);
            this.rate = options.rate || 0;
            this.min = parseFloat(options.min || 0);
            this.max = parseFloat(options.max || 0);

            this.$watch('value', () => {
                this.invalidValue = false;
                let val = this.value.replace(/,/g, '').replace(/ /g, '').trim();

                if (val.length > 0) {
                    val = parseFloat(val) * Math.pow(10, this.currency.fraction_digits).toFixed(0);
                    this.amount = val;
                    if (this.min !== 0 && val < this.min || this.max !== 0 && val > this.max) {
                        this.invalidValue = true;
                    }
                }
            });

            getPlanList().then(plans => {
                this.packs = plans.filter(plan => plan.billing_cycle === 'one-time');
            });
        }
    }));
}
